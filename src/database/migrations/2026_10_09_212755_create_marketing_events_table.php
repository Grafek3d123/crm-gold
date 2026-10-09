<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('event_key', 128);
            $table->string('event_type', 64);
            $table->string('path', 512)->nullable();
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 128)->nullable();
            $table->string('utm_content', 128)->nullable();
            $table->string('utm_term', 128)->nullable();
            $table->string('client_id', 255)->nullable();
            $table->string('yclid', 255)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->timestampTz('occurred_at');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'event_key'], 'marketing_events_site_key_unique');
            $table->index(['occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_events');
    }
};