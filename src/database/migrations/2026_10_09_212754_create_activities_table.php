<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('type', 64);
            $table->string('direction', 8)->default('in');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('inquiry_id')->nullable()->constrained('inquiries')->nullOnDelete();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('happened_at');
            $table->string('channel', 32)->nullable();
            $table->text('note')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'happened_at']);
            $table->index(['inquiry_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};