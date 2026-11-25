<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('website_contact_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->string('subject', 180);
            $table->text('message');
            $table->string('status', 20)->default('new');
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_contact_messages');
    }
};
