<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

/** Atomic, company-scoped, per-document counters for NEW records only. */
final class CompanyDocumentNumberService
{
    private const KINDS = [
        'quote' => ['Q', 'service_quotes', 'reference_number'],
        'invoice' => ['INV', 'service_invoices', 'invoice_number'],
        'addendum' => ['ADD', 'addenda', 'number'],
        'site_report' => ['SR', 'site_reports', 'number'],
        'meeting_minutes' => ['MM', 'site_reports', 'number'],
        'work_acceptance' => ['WA', 'work_acceptances', 'number'],
        'general_document' => ['GD', 'general_documents', 'number'],
        'upload' => ['DOC', 'managed_documents', 'number'],
    ];

    public function next(int $companyId, string $kind): string
    {
        $spec = self::KINDS[$kind] ?? null;
        if ($spec === null || $companyId < 1) {
            throw new LogicException('Unknown document type or company.');
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Number allocation must be transactional.');
        }
        // Serialize ALL first-use allocations by locking a real company row.
        DB::table('companies')->where('id', $companyId)->lockForUpdate()->firstOrFail();
        [$prefix, $table, $field] = $spec;
        $scope = DB::table('company_document_sequences')
            ->where('company_id', $companyId)->where('document_type', $kind);
        $counter = (clone $scope)->lockForUpdate()->first();
        if ($counter === null) {
            $max = $this->maxExisting($companyId, $table, $field, $prefix);
            DB::table('company_document_sequences')->insert([
                'company_id'=>$companyId,'document_type'=>$kind,
                'last_number'=>$max,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $next = $max + 1;
        } else {
            $next = (int) $counter->last_number + 1;
        }
        // Recheck collisions with legacy/manual numbers before reserving.
        while (true) {
            $number = $prefix.'-'.str_pad((string)$next, 3, '0', STR_PAD_LEFT);
            if (!$this->exists($companyId, $table, $field, $number, $kind)) {
                break;
            }
            $next++;
        }
        DB::table('company_document_sequences')
            ->where('company_id',$companyId)->where('document_type',$kind)
            ->update(['last_number'=>$next,'updated_at'=>now()]);
        return $number;
    }

    private function maxExisting(int $companyId, string $table, string $field,
        string $prefix): int
    {
        $max = 0;
        $columns = $table === 'service_quotes'
            ? [$field, 'custom_quote_number'] : [$field];
        foreach ($columns as $column) {
            $values = DB::table($table)->where('company_id',$companyId)
                ->where($column,'like',$prefix.'-%')->pluck($column);
            foreach ($values as $value) {
                if (preg_match('/^'.preg_quote($prefix, '/').'-([0-9]+)$/iD',
                    (string)$value, $match)) {
                    $max = max($max, (int)$match[1]);
                }
            }
        }
        return $max;
    }

    private function exists(int $companyId, string $table, string $field,
        string $number, string $kind): bool
    {
        return DB::table($table)->where('company_id',$companyId)
            ->where(function ($q) use ($field,$number,$kind): void {
                $q->where($field,$number);
                if ($kind === 'quote') {
                    $q->orWhere('custom_quote_number',$number);
                }
            })->exists();
    }
}
