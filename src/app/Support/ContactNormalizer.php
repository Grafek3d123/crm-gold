<?php

namespace App\Support;

class ContactNormalizer
{
    public static function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            return null;
        }

        // Русский номер = код 7/8 + 10 цифр абонента
        if (strlen($digits) === 11 && str_starts_with($digits, '7')) {
            return $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            return '7'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '7'.$digits;
        }

        // Длиннее/короче ожидаемого — берём последние 10 цифр абонента
        if (strlen($digits) > 10) {
            return '7'.substr($digits, -10);
        }

        return $digits;
    }

    public static function email(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return strtolower($value);
    }
}
