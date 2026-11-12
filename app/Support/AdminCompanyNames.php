<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only display helper for company names in cross-company tables/forms.
 *
 * Keep numeric company IDs in all form values, routes, APIs and audit records.
 * The names are loaded once per HTTP request (not cached across requests),
 * including inactive companies for historic records.
 */
final class AdminCompanyNames
{
    public static function label(int|string|null $companyId): string
    {
        if ($companyId === null || $companyId === '' || (int) $companyId <= 0) {
            return '—';
        }

        $request = request();
        $names = $request->attributes->get('sign2digital_admin_company_name_map');
        if (! $names instanceof Collection) {
            $names = DB::table('companies')->pluck('name', 'id');
            $request->attributes->set('sign2digital_admin_company_name_map', $names);
        }

        $name = $names->get((int) $companyId);
        return is_string($name) && trim($name) !== '' ? $name : 'Unknown company';
    }
}
