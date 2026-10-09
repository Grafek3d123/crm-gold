<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'google', 'name' => 'Google', 'is_active' => true],
            ['code' => 'yandex', 'name' => 'Yandex', 'is_active' => true],
            ['code' => 'telegram', 'name' => 'Telegram', 'is_active' => true],
            ['code' => 'vk', 'name' => 'VK', 'is_active' => true],
            ['code' => 'direct', 'name' => 'Прямой', 'is_active' => true],
            ['code' => 'referral', 'name' => 'Рекомендация', 'is_active' => true],
            ['code' => 'other', 'name' => 'Другое', 'is_active' => true],
        ];

        foreach ($rows as $row) {
            Source::query()->updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }
    }
}