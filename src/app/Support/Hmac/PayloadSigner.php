<?php

namespace App\Support\Hmac;

class PayloadSigner
{
    public static function canonicalString(
        string $method,
        string $pathWithoutQuery,
        string $timestamp,
        string $rawBody,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $pathWithoutQuery,
            $timestamp,
            hash('sha256', $rawBody),
        ]);
    }

    public static function sign(string $secret, string $canonicalString): string
    {
        return hash_hmac('sha256', $canonicalString, $secret);
    }
}
