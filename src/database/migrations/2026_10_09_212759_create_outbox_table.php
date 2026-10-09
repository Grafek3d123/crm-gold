<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->string('target', 128)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};