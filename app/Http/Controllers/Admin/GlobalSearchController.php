<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\GlobalSearchService;
use Illuminate\Http\Request;

final class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $search)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'types' => ['nullable', 'string', 'max:240', 'regex:/^[a-z_,]+$/'],
            'company_id' => ['nullable', 'integer', 'min:1', 'exists:companies,id'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'job_id' => ['nullable', 'integer', 'min:1'],
            'document_type' => ['nullable', 'string', 'max:35', 'regex:/^[a-z0-9_-]+$/i'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string', 'max:25', 'regex:/^[a-z0-9_-]+$/i'],
            'page' => ['nullable', 'integer', 'between:1,10000'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $result = $search->search($filters, isset($filters['company_id'])
            ? (int) $filters['company_id'] : null, GlobalSearchService::types());
        $routes = [
            'clients' => 'admin.clients.edit', 'jobs' => 'admin.jobs.edit',
            'quotes' => 'admin.quotes.edit', 'documents' => 'admin.documents.show',
            'general_documents' => 'admin.general-documents.edit',
            'invoices' => 'admin.invoices.edit', 'expenses' => 'admin.expenses.edit',
            'equipment' => 'admin.equipment.edit', 'deposits' => 'admin.deposits.edit',
            'reports' => 'admin.reports.edit', 'reminders' => 'admin.reminders.edit',
        ];
        foreach ($result['records'] as &$record) {
            $record['url'] = route($routes[$record['type']], $record['id']);
        }
        unset($record);
        return view('admin.search.index', [
            'result' => $result,
            'filters' => $filters,
            'types' => GlobalSearchService::types(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
