<?php

namespace Database\Seeders;

use App\Models\Site;
use Illuminate\Database\Seeder;

class SiteSeeder extends Seeder
{
    public function run(): void
    {
        Site::query()->delete();

        $rows = [
            ['code' => 'us812', 'domain' => 'us812.ru', 'name' => 'us812.ru', 'is_active' => true],
            ['code' => 'zoloto', 'domain' => 'zoloto-spb.ru', 'name' => 'zoloto-spb.ru', 'is_active' => true],
        ];

        foreach ($rows as $row) {
            Site::query()->updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }
    }
}