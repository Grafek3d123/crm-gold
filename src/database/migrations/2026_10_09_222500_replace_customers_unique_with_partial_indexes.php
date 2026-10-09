<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Снимаем составной unique, который допускает дубли при разном is_merged
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_phone_unique');
            $table->dropUnique('customers_email_unique');
        });

        // Частичные уникальные индексы: только активные, не объединённые карточки
        DB::statement("CREATE UNIQUE INDEX customers_phone_unique ON customers (phone_normalized) WHERE phone_normalized IS NOT NULL AND is_merged = false");
        DB::statement("CREATE UNIQUE INDEX customers_email_unique ON customers (email_normalized) WHERE email_normalized IS NOT NULL AND is_merged = false");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_phone_unique');
        DB::statement('DROP INDEX IF EXISTS customers_email_unique');

        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['phone_normalized', 'is_merged'], 'customers_phone_unique');
            $table->unique(['email_normalized', 'is_merged'], 'customers_email_unique');
        });
    }
};
