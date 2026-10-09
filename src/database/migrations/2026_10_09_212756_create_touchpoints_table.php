<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('touchpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('inquiry_id')->nullable()->constrained('inquiries')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('source_kind', 32);
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 128)->nullable();
            $table->string('path', 512)->nullable();
            $table->timestampTz('touched_at');
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'touched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('touchpoints');
    }
};