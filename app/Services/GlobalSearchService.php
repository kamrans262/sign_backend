<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M21: a small, permission-filtered SQL UNION search.
 * Each source has a fixed SQL column allowlist: never interpolate request input
 * as a column name or return the sensitive contents of documents/receipts.
 */
final class GlobalSearchService
{
    private const SOURCES = [
        'clients' => ['clients', 'full_name', 'company_name', 'email', 'NULL', 'id', 'NULL', 'NULL', 'clients.read'],
        'jobs' => ['service_jobs', 'title', 'reference_number', 'reference_number', 'stage', 'client_id', 'id', 'NULL', 'jobs.read'],
        'quotes' => ['service_quotes', 'client_name', 'reference_number', 'reference_number', 'status', 'client_id', 'service_job_id', 'NULL', 'quotes.read'],
        'documents' => ['managed_documents', 'title', 'number', 'number', 'status', 'client_id', 'service_job_id', 'category', 'documents.read'],
        'general_documents' => ['general_documents', 'title', 'number', 'number', 'status', 'client_id', 'service_job_id', 'document_type', 'documents.read'],
        'invoices' => ['service_invoices', 'invoice_number', 'client_name_snapshot', 'invoice_number', 'status', 'client_id', 'service_job_id', 'invoice_type', 'invoices.read'],
        'expenses' => ['job_expenses', 'supplier', 'category', 'category', 'status', 'NULL', 'service_job_id', 'NULL', 'expenses.read'],
        'equipment' => ['equipment_assets', 'name', 'code', 'code', 'status', 'NULL', 'NULL', 'NULL', 'equipment.read'],
        'deposits' => ['job_deposits', 'client_name_snapshot', 'reference_number', 'reference_number', 'status', 'NULL', 'service_job_id', 'NULL', 'invoices.read'],
        'reports' => ['site_reports', 'title', 'number', 'number', 'status', 'client_id', 'service_job_id', 'kind', 'reports.read'],
        'reminders' => ['m20_reminders', 'title', 'type', 'type', 'status', 'NULL', 'NULL', 'NULL', 'reminders.read'],
    ];

    public static function types(): array
    {
        return array_keys(self::SOURCES);
    }

    public static function permissionFor(string $type): ?string
    {
        return self::SOURCES[$type][8] ?? null;
    }


    /** Match job and invoice statuses shown by their authoritative modules. */
    private function statusExpression(string $type, string $column): string
    {
        if ($type === 'jobs') {
            return "CASE WHEN category = 'archived' THEN 'archived' "
                ."WHEN stage != 'completed' AND due_date < CURRENT_DATE THEN 'overdue' "
                ."ELSE stage END";
        }
        if ($type === 'invoices') {
            // Reconcile only recorded payments and deposits that remain received.
            // A refunded deposit must never make an invoice look paid.
            $paid = "(SELECT COALESCE(SUM(p.amount_minor), 0) "
                ."FROM service_invoice_payments p WHERE p.company_id = service_invoices.company_id "
                ."AND p.service_invoice_id = service_invoices.id AND p.status = 'recorded')";
            $deposits = "(SELECT COALESCE(SUM(a.amount_minor), 0) "
                ."FROM service_invoice_deposit_allocations a "
                ."JOIN job_deposits d ON d.id = a.job_deposit_id AND d.company_id = a.company_id "
                ."WHERE a.company_id = service_invoices.company_id "
                ."AND a.service_invoice_id = service_invoices.id AND d.status = 'received')";
            $credited = "($paid + $deposits)";
            return "CASE WHEN service_invoices.status IN ('approved', 'sent') THEN "
                ."CASE WHEN $credited >= service_invoices.total_minor THEN 'paid' "
                ."WHEN $credited > 0 THEN 'partially_paid' "
                ."WHEN service_invoices.due_date < CURRENT_DATE THEN 'overdue' "
                ."WHEN service_invoices.status = 'approved' THEN 'pending' "
                ."ELSE 'sent' END ELSE service_invoices.status END";
        }
        return $column;
    }

    public function search(array $filters, ?int $companyId, array $allowedTypes, ?int $actorId = null): array
    {
        $requested = trim((string) ($filters['types'] ?? ''));
        $requestedTypes = $requested === '' ? self::types() : explode(',', $requested);
        if (count($requestedTypes) !== count(array_unique($requestedTypes))
            || array_diff($requestedTypes, self::types())) {
            throw ValidationException::withMessages(['types' => 'Invalid search type.']);
        }
        $types = array_values(array_intersect(self::types(), $requestedTypes, $allowedTypes));
        $needle = trim((string) ($filters['q'] ?? ''));
        // Use a portable explicit escape for both SQLite and MySQL.
        $escaped = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';
        $union = null;
        foreach ($types as $type) {
            [$table, $title, $subtitle, $reference, $status, $client, $job, $docType] = self::SOURCES[$type];
            if (isset($filters['client_id']) && $client === 'NULL') continue;
            if (isset($filters['job_id']) && $job === 'NULL') continue;
            if (isset($filters['document_type']) && $docType === 'NULL') continue;
            if (isset($filters['status']) && $status === 'NULL') continue;

            $query = DB::table($table);
            $status = $this->statusExpression($type, $status);
            if ($companyId !== null) $query->where('company_id', $companyId);
            if ($type === 'clients') $query->whereNull('deleted_at');
            // A site report has its own searchable canonical record. Do not list
            // its managed PDF twice or offer two destinations for one report.
            if ($type === 'documents') {
                $query->whereNotIn('id', static function ($linked): void {
                    $linked->select('managed_document_id')->from('site_reports')
                        ->whereNotNull('managed_document_id');
                });
            }
            // Personal reminders must not be readable by another team member.
            if ($type === 'reminders' && $actorId !== null) {
                $query->where('owner_user_id', $actorId);
            }
            if (isset($filters['client_id'])) $query->where($client, (int) $filters['client_id']);
            if (isset($filters['job_id'])) $query->where($job, (int) $filters['job_id']);
            if (isset($filters['document_type'])) $query->where($docType, $filters['document_type']);
            if (isset($filters['status'])) $query->whereRaw('('.$status.') = ?', [$filters['status']]);
            if (isset($filters['date_from'])) $query->where('created_at', '>=', $filters['date_from'].' 00:00:00');
            if (isset($filters['date_to'])) $query->where('created_at', '<=', $filters['date_to'].' 23:59:59');
            if ($needle !== '') {
                $columns = array_values(array_unique(array_filter([
                    $title, $subtitle, $reference,
                    $type === 'clients' ? 'email' : null,
                    $type === 'equipment' ? 'brand' : null,
                ])));
                $query->where(function ($match) use ($columns, $escaped): void {
                    foreach ($columns as $column) {
                        $match->orWhereRaw($column." LIKE ? ESCAPE '!'", [$escaped]);
                    }
                });
            }
            $query->selectRaw(
                '? AS type, id, company_id, '.$title.' AS title, '
                .$subtitle.' AS subtitle, '.$reference.' AS reference, '
                .$status.' AS status, '.$client.' AS client_id, '
                .$job.' AS job_id, '.$docType.' AS document_type, '
                .'created_at AS occurred_at',
                [$type]
            );
            $union = $union ? $union->unionAll($query) : $query;
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);
        if ($union === null) {
            return ['records' => [], 'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => 0,
                'last_page' => 1, 'has_more' => false,
            ]];
        }
        // SQL pagination/count, not an unbounded in-memory collection.
        $paginator = DB::query()->fromSub($union, 'search_results')
            ->orderByDesc('occurred_at')->orderBy('type')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
        $records = $paginator->getCollection()->map(static fn ($row): array => [
            'type' => (string) $row->type,
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'title' => (string) ($row->title ?? ''),
            'subtitle' => (string) ($row->subtitle ?? ''),
            'reference' => (string) ($row->reference ?? ''),
            'status' => (string) ($row->status ?? ''),
            'client_id' => $row->client_id === null ? null : (int) $row->client_id,
            'job_id' => $row->job_id === null ? null : (int) $row->job_id,
            'document_type' => $row->document_type,
            'date' => (string) $row->occurred_at,
        ])->values()->all();

        return ['records' => $records, 'pagination' => [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'has_more' => $paginator->hasMorePages(),
        ]];
    }
}
