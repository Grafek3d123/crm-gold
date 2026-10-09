<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            // Полностью покрывается префиксом inquiries_site_external_unique
            $table->dropIndex('inquiries_site_external_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->index(['site_id', 'external_id'], 'inquiries_site_external_idx');
        });
    }
};
