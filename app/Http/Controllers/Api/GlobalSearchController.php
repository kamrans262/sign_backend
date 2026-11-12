<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\GlobalSearchService;
use App\Support\CompanyRolePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $search): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'types' => ['nullable', 'string', 'max:240', 'regex:/^[a-z_,]+$/'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'job_id' => ['nullable', 'integer', 'min:1'],
            'document_type' => ['nullable', 'string', 'max:35', 'regex:/^[a-z0-9_-]+$/i'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string', 'max:25', 'regex:/^[a-z0-9_-]+$/i'],
            'page' => ['nullable', 'integer', 'between:1,10000'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $company = $request->attributes->get('company');
        abort_unless($company instanceof Company, 403);
        if (isset($filters['company_id']) && (int) $filters['company_id'] !== (int) $company->id) {
            abort(403, 'Company access denied.');
        }
        $member = $company->users()->where('users.id', $request->user()->id)
            ->wherePivot('status', 'active')->first();
        abort_unless($member, 403);
        if ($member->pivot->role === 'owner') {
            $allowed = GlobalSearchService::types();
        } else {
            $permissions = CompanyRolePermissions::resolve(
                $member->pivot->company_role_id ? (int) $member->pivot->company_role_id : null,
                (int) $company->id, (string) $member->pivot->role,
                $member->pivot->permissions
            );
            $allowed = array_values(array_filter(GlobalSearchService::types(),
                static function (string $type) use ($permissions): bool {
                    if ($type === 'reminders') return true;
                    $needed = GlobalSearchService::permissionFor($type);
                    if ($type === 'invoices' || $type === 'deposits') {
                        return in_array('invoices.read', $permissions, true)
                            && in_array('jobs.read', $permissions, true);
                    }
                    return $needed !== null && in_array($needed, $permissions, true);
                }));
        }
        $result = $search->search($filters, (int) $company->id, $allowed, (int) $request->user()->id);
        return response()->json(['data' => $result], 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
