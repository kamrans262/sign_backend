<?php

namespace App\Services;

use App\Models\ServiceJob;
use Illuminate\Support\Facades\DB;

/**
 * Persisted, tenant-scoped M5-M19 job detail summary. Monetary values are
 * integer CHF cents; draft, voided and refunded rows never count as money
 * received. This reads existing module tables rather than preview fixtures.
 */
final class JobDetailsSnapshot
{
    public function forJob(
        ServiceJob $job,
        bool $canReadQuotes,
        bool $canReadDocuments
    ): array {
        $company = (int) $job->company_id;
        $id = (int) $job->id;
        $query = static fn (string $table) => DB::table($table)
            ->where('company_id', $company)
            ->where('service_job_id', $id);

        $quoteMinor = $canReadQuotes
            ? (int) $query('service_quotes')
                ->whereIn('status', ['approved', 'sent'])
                ->where('currency', 'CHF')->sum('total_minor')
            : null;
        $expenseMinor = $canReadDocuments
            ? (int) $query('job_expenses')
                ->whereIn('status', ['approved', 'reimbursed'])
                ->where('currency', 'CHF')->sum('amount_minor')
            : null;

        $approvedLabour = $query('job_labour_entries')
            ->where('status', 'approved')->where('currency', 'CHF');
        $labourMinor = (clone $approvedLabour)->whereNull('cost_minor')->exists()
            ? null : (int) $approvedLabour->sum('cost_minor');

        $depositMinor = $canReadDocuments
            ? (int) $query('job_deposits')
                ->where('status', 'received')->where('currency', 'CHF')
                ->sum('amount_minor')
            : null;

        // M17 invoice amounts exclude drafts and voids. Payment rows have
        // their own status and cannot be inferred from a sent invoice.
        $invoiceMinor = $canReadDocuments
            ? (int) $query('service_invoices')
                ->whereIn('status', ['approved', 'sent', 'issued'])
                ->where('currency', 'CHF')->sum('total_minor')
            : null;
        $paymentMinor = $canReadDocuments
            ? (int) DB::table('service_invoice_payments as payments')
                ->join('service_invoices as invoices',
                    'invoices.id', '=', 'payments.service_invoice_id')
                ->where('invoices.company_id', $company)
                ->where('invoices.service_job_id', $id)
                ->where('invoices.currency', 'CHF')
                ->where('payments.company_id', $company)
                ->where('payments.status', 'recorded')
                ->whereIn('invoices.status', ['approved', 'sent', 'issued'])
                ->sum('payments.amount_minor')
            : null;
        $receivedMinor = $paymentMinor === null || $depositMinor === null
            ? null : $depositMinor + $paymentMinor;
        // No issued invoice means no customer receivable has been established.
        // It is not a zero balance, and the job expense budget is unrelated.
        $hasReceivable = $canReadDocuments
            ? $query('service_invoices')
                ->whereIn('status', ['approved', 'sent', 'issued'])
                ->where('currency', 'CHF')->exists()
            : false;
        $remainingMinor = !$hasReceivable || $invoiceMinor === null ||
                $receivedMinor === null
            ? null : max(0, $invoiceMinor - $receivedMinor);

        $minor = [
            'quote_amount_minor' => $quoteMinor,
            'expenses_minor' => $expenseMinor,
            'labour_cost_minor' => $labourMinor,
            'deposits_minor' => $depositMinor,
            'invoiced_minor' => $invoiceMinor,
            'received_minor' => $receivedMinor,
            'remaining_balance_minor' => $remainingMinor,
        ];
        $chf = [];
        foreach ($minor as $key => $value) {
            $chf[str_replace('_minor', '_chf', $key)] = $value === null
                ? null : $value / 100;
        }

        $counts = [
            'quotes' => $canReadQuotes
                ? $query('service_quotes')->where('status', '!=', 'archived')->count()
                : null,
            'addenda' => $canReadDocuments
                ? $query('addenda')->where('status', '!=', 'archived')->count()
                : null,
            'documents' => $canReadDocuments
                ? $query('managed_documents')->count()
                : null,
            'photos' => $query('job_photos')->whereNull('archived_at')->count(),
            'notes' => $query('job_notes')->whereNull('archived_at')->count(),
            'purchases' => $canReadDocuments
                ? $query('job_expenses')->count() : null,
            'time' => $query('job_labour_entries')
                ->whereNull('voided_at')->count(),
            'deposits' => $canReadDocuments
                ? $query('job_deposits')->where('status', '!=', 'void')->count()
                : null,
            'site_reports' => $canReadDocuments
                ? $query('site_reports')->count() : null,
            'acceptance' => $canReadDocuments
                ? $query('work_acceptances')->count() : null,
            'invoices' => $canReadDocuments
                ? $query('service_invoices')->where('status', '!=', 'void')->count()
                : null,
        ];

        return [
            'financials' => $chf,
            'financials_minor' => $minor,
            'section_counts' => $counts,
        ];
    }
}
