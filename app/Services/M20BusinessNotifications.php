<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * M20: derive events only from verified, company-authoritative business state.
 * No customer email, no SMS and no M19 borrower reminder side effects.
 */
final class M20BusinessNotifications
{
    public function __construct(private readonly M20NotificationTemplates $templates) {}

    private function authorizedOwner($query)
    {
        return $query
            ->join('companies as c', 'c.id', '=', 'source.company_id')
            ->join('company_user as cu', function ($join): void {
                $join->on('cu.company_id', '=', 'c.id')
                    ->on('cu.user_id', '=', 'c.owner_user_id');
            })
            ->join('users as u', 'u.id', '=', 'c.owner_user_id')
            ->where('c.status', 'active')
            ->where('cu.status', 'active')
            ->where('u.is_active', true);
    }

    private function emit(
        int $companyId, int $owner, string $key, string $event,
        string $category, array $variables,
    ): bool {
        $prefs = DB::table('m20_notification_preferences')
            ->where('company_id', $companyId)->where('user_id', $owner)->first();
        if ($prefs && !$prefs->in_app_enabled && !$prefs->push_enabled) return false;
        $copy = $this->templates->render($key, 'in_app', $prefs?->locale ?? 'en', $variables);
        if (!$copy) return false;
        return DB::table('m20_notifications')->insertOrIgnore([
            'company_id' => $companyId, 'recipient_user_id' => $owner,
            'template_revision_id' => $copy['revision_id'],
            'event_key' => $event, 'category' => $category,
            'title' => $copy['title'], 'body' => $copy['body'],
            // A notification is NEVER a capability grant. Business screens
            // independently authorize records and company membership.
            'link_type' => null, 'link_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]) === 1;
    }

    public function sync(int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $counts = ['invoice' => 0, 'equipment' => 0, 'document' => 0, 'customer' => 0];
        // Invoices: status sent, due date reached, void-free, and a positive
        // unpaid remainder after confirmed payments and deposit allocations.
        $invoices = $this->authorizedOwner(DB::table('service_invoices as source'))
            ->where('source.status', 'sent')->whereNull('source.voided_at')
            ->where('source.due_date', '<=', today()->toDateString())
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('m20_notifications as n')
                    ->whereColumn('n.company_id', 'source.company_id')
                    ->whereColumn('n.recipient_user_id', 'c.owner_user_id')
                    ->whereRaw((DB::connection()->getDriverName() === 'sqlite' ? "n.event_key = ('invoice.payment_due.' || source.id)" : "n.event_key = CONCAT('invoice.payment_due.', source.id)"));
            })
            ->select('source.id', 'source.company_id', 'source.total_minor',
                'source.invoice_number', 'c.owner_user_id')
            ->orderByDesc('source.id')->limit($limit)->get();
        foreach ($invoices as $invoice) {
            $paid = (int) DB::table('service_invoice_payments')
                ->where('company_id', $invoice->company_id)
                ->where('service_invoice_id', $invoice->id)
                ->where('status', 'recorded')->sum('amount_minor');
            $deposits = (int) DB::table('service_invoice_deposit_allocations')
                ->where('company_id', $invoice->company_id)
                ->where('service_invoice_id', $invoice->id)->sum('amount_minor');
            if ($paid + $deposits >= (int) $invoice->total_minor) continue;
            $counts['invoice'] += (int) $this->emit(
                $invoice->company_id, $invoice->owner_user_id,
                'invoice.payment_due', 'invoice.payment_due.'.$invoice->id,
                'invoice', ['invoice_number' => $invoice->invoice_number]
            );
        }

        $loans = $this->authorizedOwner(DB::table('equipment_loans as source'))
            ->join('equipment_assets as a', 'a.id', '=', 'source.equipment_asset_id')
            ->whereColumn('a.company_id', 'source.company_id')
            ->where('source.status', 'active')->whereNull('source.returned_at')
            ->whereNull('source.actual_return_date')
            ->where('source.expected_return_date', '<', today()->toDateString())
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('m20_notifications as n')
                    ->whereColumn('n.company_id', 'source.company_id')
                    ->whereColumn('n.recipient_user_id', 'c.owner_user_id')
                    ->whereRaw((DB::connection()->getDriverName() === 'sqlite' ? "n.event_key = ('equipment.loan_overdue.' || source.id)" : "n.event_key = CONCAT('equipment.loan_overdue.', source.id)"));
            })
            ->select('source.id', 'source.company_id', 'c.owner_user_id',
                'a.name as equipment_name')
            ->orderByDesc('source.id')->limit($limit)->get();
        foreach ($loans as $loan) {
            $counts['equipment'] += (int) $this->emit(
                $loan->company_id, $loan->owner_user_id,
                'equipment.loan_overdue', 'equipment.loan_overdue.'.$loan->id,
                'equipment', ['equipment_name' => $loan->equipment_name]
            );
        }

        // Only real, stored signed artifacts count as completed signatures.
        // This does not expose signatures or document PDFs in the push payload.
        $signed = $this->authorizedOwner(DB::table('signature_requests as source'))
            ->where('source.status', 'signed')->whereNotNull('source.signed_at')
            ->whereExists(function ($q): void {
                $q->selectRaw('1')->from('signed_document_artifacts as a')
                    ->whereColumn('a.signature_request_id', 'source.id')
                    ->whereColumn('a.company_id', 'source.company_id');
            })
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('m20_notifications as n')
                    ->whereColumn('n.company_id', 'source.company_id')
                    ->whereColumn('n.recipient_user_id', 'c.owner_user_id')
                    ->whereRaw((DB::connection()->getDriverName() === 'sqlite' ? "n.event_key = ('document.signed.' || source.id)" : "n.event_key = CONCAT('document.signed.', source.id)"));
            })
            ->select('source.id', 'source.company_id', 'c.owner_user_id')
            ->orderByDesc('source.id')->limit($limit)->get();
        foreach ($signed as $item) {
            $counts['document'] += (int) $this->emit(
                $item->company_id, $item->owner_user_id,
                'document.signed', 'document.signed.'.$item->id,
                'document', []
            );
        }
        // Verified M13 customer reservations require a company staff review.
        // Only the completed/signed artifact and decision are inspected; neither
        // the reservation text nor customer evidence is emitted to a lock screen.
        $reservations = $this->authorizedOwner(DB::table('work_acceptances as source'))
            ->where('source.status', 'accepted_with_reservations')
            ->where('source.acceptance_result', 'accepted_with_reservations')
            ->whereNotNull('source.signed_at')
            ->whereExists(function ($q): void {
                $q->selectRaw('1')->from('work_acceptance_artifacts as a')
                    ->whereColumn('a.work_acceptance_id', 'source.id')
                    ->whereColumn('a.company_id', 'source.company_id');
            })
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('m20_notifications as n')
                    ->whereColumn('n.company_id', 'source.company_id')
                    ->whereColumn('n.recipient_user_id', 'c.owner_user_id')
                    ->whereRaw((DB::connection()->getDriverName() === 'sqlite'
                        ? "n.event_key = ('customer.action_required.' || source.id)"
                        : "n.event_key = CONCAT('customer.action_required.', source.id)"));
            })
            ->select('source.id', 'source.company_id', 'c.owner_user_id')
            ->orderByDesc('source.id')->limit($limit)->get();
        foreach ($reservations as $item) {
            $counts['customer'] += (int) $this->emit(
                $item->company_id, $item->owner_user_id,
                'customer.action_required', 'customer.action_required.'.$item->id,
                'customer', []
            );
        }
        return $counts;
    }
    /** Recheck source state immediately before dispatching a private notification. */
    public function actionable(object $notification): bool
    {
        if ($notification->reminder_id) return true; // reminder validity is independently checked.
        $companyId = (int) $notification->company_id;
        $parts = explode('.', (string) $notification->event_key);
        $id = (int) ($parts[2] ?? 0);
        if ($id < 1) return false;
        $event = implode('.', array_slice($parts, 0, 2));
        if ($event === 'invoice.payment_due') {
            $record = DB::table('service_invoices')->where('id', $id)
                ->where('company_id', $companyId)->where('status', 'sent')
                ->whereNull('voided_at')->where('due_date', '<=', today()->toDateString())
                ->first(['id', 'total_minor']);
            if (!$record) return false;
            $paid = (int) DB::table('service_invoice_payments')
                ->where('company_id', $companyId)->where('service_invoice_id', $id)
                ->where('status', 'recorded')->sum('amount_minor');
            $deposits = (int) DB::table('service_invoice_deposit_allocations')
                ->where('company_id', $companyId)->where('service_invoice_id', $id)
                ->sum('amount_minor');
            return ($paid + $deposits) < (int) $record->total_minor;
        }
        if ($event === 'equipment.loan_overdue') {
            return DB::table('equipment_loans')->where('id', $id)
                ->where('company_id', $companyId)->where('status', 'active')
                ->whereNull('returned_at')->whereNull('actual_return_date')
                ->where('expected_return_date', '<', today()->toDateString())->exists();
        }
        if ($event === 'customer.action_required') {
            return DB::table('work_acceptances as w')
                ->where('w.id', $id)->where('w.company_id', $companyId)
                ->where('w.status', 'accepted_with_reservations')
                ->where('w.acceptance_result', 'accepted_with_reservations')
                ->whereNotNull('w.signed_at')
                ->whereExists(function ($q): void {
                    $q->selectRaw('1')->from('work_acceptance_artifacts as a')
                        ->whereColumn('a.work_acceptance_id', 'w.id')
                        ->whereColumn('a.company_id', 'w.company_id');
                })->exists();
        }
        if ($event === 'document.signed') {
            return DB::table('signature_requests as sr')
                ->where('sr.id', $id)->where('sr.company_id', $companyId)
                ->where('sr.status', 'signed')->whereNotNull('sr.signed_at')
                ->whereExists(function ($q): void {
                    $q->selectRaw('1')->from('signed_document_artifacts as a')
                        ->whereColumn('a.signature_request_id', 'sr.id')
                        ->whereColumn('a.company_id', 'sr.company_id');
                })->exists();
        }
        return false; // No unverified business/system event types are pushed.
    }

}
