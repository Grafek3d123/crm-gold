<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->string('entity_type', 128)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_events');
    }
};