<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Активность привязана явными customer_id / inquiry_id,
        // полиморфный subject — опциональная связь
        DB::statement('ALTER TABLE activities ALTER COLUMN subject_type DROP NOT NULL');
        DB::statement('ALTER TABLE activities ALTER COLUMN subject_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE activities SET subject_type = \'App\\Models\\Customer\', subject_id = customer_id WHERE subject_type IS NULL');
        DB::statement('ALTER TABLE activities ALTER COLUMN subject_type SET NOT NULL');
        DB::statement('ALTER TABLE activities ALTER COLUMN subject_id SET NOT NULL');
    }
};
