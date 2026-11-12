<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('company_user')) return;

        // Before role-based module enforcement, admins bypassed all module
        // checks. Preserve that existing access for legacy unconfigured admins.
        // Explicit grants made after this migration remain authoritative.
        $all = [
            'clients.read', 'clients.write', 'jobs.read', 'jobs.write',
            'documents.read', 'documents.write', 'quotes.read', 'quotes.write',
            'invoices.read', 'invoices.write', 'reports.read', 'reports.write',
            'expenses.read', 'expenses.write', 'labour.read', 'labour.write',
            'equipment.read', 'equipment.write',
        ];
        foreach (DB::table('company_user')->where('role', 'admin')
            ->get(['company_id', 'user_id', 'permissions']) as $member) {
            $grants = json_decode((string) $member->permissions, true);
            if (is_array($grants) && count($grants) > 0) continue;
            DB::table('company_user')
                ->where('company_id', $member->company_id)
                ->where('user_id', $member->user_id)
                ->update(['permissions' => json_encode($all, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        // Do not silently strip permissions belonging to existing admins.
    }
};
