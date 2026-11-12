<?php

namespace App\Services;

use App\Models\ServiceJob;
use Illuminate\Support\Facades\DB;

/**
 * Derived, company-scoped job activity progress.
 *
 * This is a weighted activity indicator, not a claim that a measured percentage
 * of physical construction has been independently inspected. Every milestone
 * is backed by a live, non-voided record of work or its documented lifecycle.
 * No client or administrator can submit a numeric percentage.
 *
 * Total activity weights = 100. Active jobs are capped at 95; 100 means the
 * job itself has been explicitly marked completed. All percentages are
 * recalculated on reads so removing/revoking qualifying records lowers progress.
 *
 * Queries are grouped by company and job IDs to avoid N+1 requests on list APIs.
 */
final class JobProgressCalculator
{
    /**
     * @param iterable<ServiceJob> $jobs
     * @return array<int, int> keyed by service job ID
     */
    public function forJobs(iterable $jobs): array
    {
        $scores = [];
        $companies = [];
        $completedIds = [];
        foreach ($jobs as $job) {
            $id = (int) $job->id;
            $company = (int) $job->company_id;
            if ($job->category === 'completed' || $job->stage === 'completed') {
                $scores[$id] = 100;
                $completedIds[$id] = true;
            } else {
                $scores[$id] = 0;
                $companies[$company][] = $id;
            }
        }

        foreach ($companies as $companyId => $ids) {
            // De-duplicate each milestone: repeated invoices, notes, photos and
            // expenses do not continually inflate the job's percentage.
            $mark = static function ($query, int $weight, string $column = 'service_job_id') use (&$scores): void {
                foreach ($query->distinct()->pluck($column) as $id) {
                    $scores[(int) $id] += $weight;
                }
            };
            $base = static fn (string $table) => DB::table($table)
                ->where('company_id', $companyId)
                ->whereIn('service_job_id', $ids);

            // Approved scope: 10%; actual customer-signed scope: another 10%.
            $mark($base('service_quotes')
                ->whereIn('status', ['approved', 'sent']), 10);
            $mark(DB::table('signed_document_artifacts as artifact')
                ->join('service_quotes as quote', 'quote.id', '=', 'artifact.service_quote_id')
                ->join('signature_requests as request', 'request.id', '=', 'artifact.signature_request_id')
                ->where('artifact.company_id', $companyId)
                ->where('quote.company_id', $companyId)
                ->where('request.company_id', $companyId)
                ->whereIn('quote.service_job_id', $ids)
                ->whereIn('quote.status', ['approved', 'sent'])
                ->where('request.status', 'signed'), 10, 'quote.service_job_id');

            // Verified purchasing and recorded work: 10% + 25%.
            $mark($base('job_expenses')
                ->whereIn('status', ['approved', 'reimbursed']), 10);
            $mark($base('job_labour_entries')
                ->where('status', 'approved')
                ->whereNull('voided_at')
                ->where('duration_minutes', '>', 0), 25);

            // Evidence of the work: active photo, approved note-derived
            // document, and an approved/sent site report or meeting minutes.
            $mark($base('job_photos')->whereNull('archived_at'), 5);
            $mark($base('job_note_documents')->where('status', 'approved'), 5);
            $mark($base('site_reports')
                ->whereIn('status', ['approved', 'sent']), 10);

            // Customer work acceptance must have been actually signed.
            $mark($base('work_acceptances')
                ->whereIn('status', ['accepted', 'accepted_with_reservations'])
                ->whereNotNull('signed_at')
                ->whereNotNull('signed_pdf_sha256'), 15);

            // Final billing and actually recorded funds: 5% + 5%.
            $mark($base('service_invoices')
                ->whereIn('status', ['approved', 'sent', 'issued']), 5);
            $paid = DB::table('service_invoice_payments as payment')
                ->join('service_invoices as invoice', 'invoice.id', '=', 'payment.service_invoice_id')
                ->where('payment.company_id', $companyId)
                ->where('invoice.company_id', $companyId)
                ->whereIn('invoice.service_job_id', $ids)
                ->whereIn('invoice.status', ['approved', 'sent', 'issued'])
                ->where('payment.status', 'recorded')
                ->distinct()->pluck('invoice.service_job_id')->all();
            $deposits = $base('job_deposits')->where('status', 'received')
                ->distinct()->pluck('service_job_id')->all();
            foreach (array_unique(array_merge($paid, $deposits)) as $id) {
                $scores[(int) $id] += 5;
            }
        }

        // No work records -> 0%; only an explicitly completed job may show 100%.
        foreach ($scores as $id => $percentage) {
            if (!isset($completedIds[$id])) {
                $scores[$id] = min(95, max(0, $percentage));
            }
        }
        return $scores;
    }
}
