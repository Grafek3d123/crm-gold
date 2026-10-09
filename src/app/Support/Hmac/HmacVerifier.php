<?php

namespace App\Support\Hmac;

use App\Models\ApiCredential;
use Illuminate\Support\Facades\Crypt;

class HmacVerifier
{
    public const MAX_SKEW_SECONDS = 300;

    public function resolve(string $keyId): ?ApiCredential
    {
        return ApiCredential::query()
            ->where('key_id', $keyId)
            ->where('is_active', true)
            ->first();
    }

    public function hasScope(ApiCredential $credential, string $requiredScope): bool
    {
        if ($credential->scope === $requiredScope) {
            return true;
        }

        // wildcard-скоуп разрешает все операции
        return in_array($credential->scope, ['*', 'all'], true);
    }

    public function secretFor(ApiCredential $credential): ?string
    {
        try {
            return Crypt::decryptString($credential->secret_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{ok: bool, error: ?string, code: ?string}
     */
    public function verify(
        ApiCredential $credential,
        string $secret,
        string $method,
        string $pathWithoutQuery,
        string $timestamp,
        string $rawBody,
        string $signature,
    ): array {
        if (! ctype_digit($timestamp)) {
            return ['ok' => false, 'error' => 'Некорректная метка времени', 'code' => 'invalid_timestamp'];
        }

        $now = time();

        if (abs($now - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return ['ok' => false, 'error' => 'Метка времени вне допустимого окна', 'code' => 'stale_timestamp'];
        }

        $canonical = PayloadSigner::canonicalString($method, $pathWithoutQuery, $timestamp, $rawBody);
        $expected = PayloadSigner::sign($secret, $canonical);

        if (! hash_equals($expected, $signature)) {
            return ['ok' => false, 'error' => 'Неверная подпись', 'code' => 'invalid_signature'];
        }

        return ['ok' => true, 'error' => null, 'code' => null];
    }
}
