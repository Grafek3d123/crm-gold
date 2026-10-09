<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('external_key', 255);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_key'], 'customer_identities_provider_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identities');
    }
};