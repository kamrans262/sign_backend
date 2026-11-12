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
        // Job Remaining Balance is the unpaid AGREED contract value, not
        // the outstanding invoice ledger. Require a signed customer quote;
        // unsigned/archived proposals must not become money owed.
        // A job may have multiple approved quotations/revisions: take the
        // latest customer-signed quote, not their sum.
        $acceptedQuote = $canReadQuotes && $canReadDocuments
            ? DB::table('service_quotes as quotes')
                ->join('signed_document_artifacts as signed',
                    function ($join) use ($company) {
                        $join->on('signed.service_quote_id', '=', 'quotes.id')
                            ->where('signed.company_id', '=', $company);
                    })
                ->join('signature_requests as requests',
                    function ($join) use ($company) {
                        $join->on('requests.id', '=', 'signed.signature_request_id')
                            ->where('requests.company_id', '=', $company)
                            ->where('requests.status', '=', 'signed');
                    })
                ->where('quotes.company_id', $company)
                ->where('quotes.service_job_id', $id)
                ->whereIn('quotes.status', ['approved', 'sent'])
                ->where('quotes.currency', 'CHF')
                ->whereNotNull('quotes.total_minor')
                ->orderByDesc('signed.created_at')
                ->orderByDesc('signed.id')
                ->select('quotes.id', 'quotes.total_minor')
                ->first()
            : null;

        $agreedMinor = $acceptedQuote === null ? null
            : (int) $acceptedQuote->total_minor;
        if ($agreedMinor !== null) {
            // Only price-changing, approved addenda linked to that signed
            // quote count. An older quote's addenda and drafts never count.
            $agreedMinor += (int) $query('addenda')
                ->where('service_quote_id', $acceptedQuote->id)
                ->where('currency', 'CHF')
                ->whereIn('status', ['approved', 'sent', 'signed'])
                ->whereNotNull('approved_pdf_sha256')
                ->sum('total_minor');
        }
        // Do not change Received, Deposits, Invoiced or invoice payments
        // used elsewhere. No signed quote => unavailable, not zero.
        $remainingMinor = $agreedMinor === null || $receivedMinor === null
            ? null : max(0, $agreedMinor - $receivedMinor);

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
