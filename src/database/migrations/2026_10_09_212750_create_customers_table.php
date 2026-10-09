<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable();
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->string('email_normalized', 255)->nullable()->index();
            $table->string('phone_raw', 32)->nullable();
            $table->string('email_raw', 255)->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_merged')->default(false);
            $table->timestamps();

            $table->unique(['phone_normalized', 'is_merged'], 'customers_phone_unique');
            $table->unique(['email_normalized', 'is_merged'], 'customers_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};