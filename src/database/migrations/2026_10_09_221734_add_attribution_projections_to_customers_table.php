<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('first_touchpoint_id')->nullable()->after('comment');
            $table->unsignedBigInteger('last_touchpoint_id')->nullable()->after('first_touchpoint_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['first_touchpoint_id', 'last_touchpoint_id']);
        });
    }
};
