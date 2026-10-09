<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('source_kind', 32);
            $table->string('status', 32)->default('new');
            $table->string('external_id', 128)->nullable();
            $table->string('name_raw', 255)->nullable();
            $table->string('phone_raw', 32)->nullable();
            $table->string('email_raw', 255)->nullable();
            $table->text('message')->nullable();
            $table->string('landing_path', 512)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 128)->nullable();
            $table->string('utm_content', 128)->nullable();
            $table->string('utm_term', 128)->nullable();
            $table->string('client_id', 255)->nullable();
            $table->string('yclid', 255)->nullable();
            $table->json('utm_raw')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('happened_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'external_id'], 'inquiries_site_external_idx');
            $table->unique(['site_id', 'external_id'], 'inquiries_site_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};