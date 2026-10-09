<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64);
            $table->string('key', 255);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('http_status');
            $table->json('response')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'key'], 'idempotency_scope_key_unique');
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency');
    }
};