<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m23_document_usage', function (Blueprint $t): void {
            // Retain a stable account identity even if the audit actor is
            // removed later. Old issued rows remain chargeable to their
            // original actor; system-issued rows belong to company owner.
            $t->unsignedBigInteger('quota_account_user_id')->nullable();
            $t->index(['company_id','quota_account_user_id','issued_at'],
                'm23_usage_account_date_idx');
        });

        DB::table('m23_document_usage')->orderBy('id')->chunkById(200,
            function ($rows): void {
                foreach ($rows as $row) {
                    $account=$row->actor_user_id ??
                        DB::table('companies')->where('id',$row->company_id)
                            ->value('owner_user_id');
                    if ($account !== null) {
                        DB::table('m23_document_usage')->where('id',$row->id)
                            ->update(['quota_account_user_id'=>(int)$account]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('m23_document_usage', function (Blueprint $t): void {
            $t->dropIndex('m23_usage_account_date_idx');
            $t->dropColumn('quota_account_user_id');
        });
    }
};
