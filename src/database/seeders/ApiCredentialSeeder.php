<?php

namespace Database\Seeders;

use App\Models\ApiCredential;
use App\Models\Site;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class ApiCredentialSeeder extends Seeder
{
    public function run(): void
    {
        $secrets = [
            'us812' => 'test-secret-us812-000000000000000000000000000000000000000000000000000000',
            'zoloto' => 'test-secret-zoloto-000000000000000000000000000000000000000000000000000000',
        ];

        foreach (Site::query()->get() as $site) {
            $secret = $secrets[$site->code] ?? null;
            if (! $secret) {
                continue;
            }

            ApiCredential::query()->updateOrCreate(
                ['site_id' => $site->id],
                [
                    'site_id' => $site->id,
                    'key_id' => 'test-' . $site->code,
                    'secret_encrypted' => Crypt::encryptString($secret),
                    'scope' => 'inquiries',
                    'is_active' => true,
                ]
            );
        }
    }
}