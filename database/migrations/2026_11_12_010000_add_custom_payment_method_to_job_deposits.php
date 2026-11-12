<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('job_deposits', function (Blueprint $table): void {
            $table->string('payment_method_custom', 120)->nullable()
                ->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('job_deposits', function (Blueprint $table): void {
            $table->dropColumn('payment_method_custom');
        });
    }
};
