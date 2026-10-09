<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed reference data only.
     *
     * Users are intentionally NOT seeded here — the first administrator is
     * created with `php artisan crm:create-admin`, which never stores a
     * fixed password in sources or seeders.
     */
    public function run(): void
    {
        $this->call([
            SiteSeeder::class,
            SourceSeeder::class,
            ApiCredentialSeeder::class,
        ]);
    }
}
