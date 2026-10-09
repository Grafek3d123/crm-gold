<?php

namespace Tests\Unit;

use App\Support\ContactNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContactNormalizerTest extends TestCase
{
    #[DataProvider('phones')]
    public function test_phone_normalization(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, ContactNormalizer::phone($input));
    }

    public static function phones(): array
    {
        return [
            'plus seven formatted' => ['+7 (999) 123-45-67', '79991234567'],
            'eight prefix' => ['8 999 123 45 67', '79991234567'],
            'ten digits' => ['9991234567', '79991234567'],
            'whitespace and dashes' => ['  +7-999-123-45-67 ', '79991234567'],
            'too many digits takes last ten' => ['+79112412234576', '72412234576'],
            'eleven with seven kept' => ['79112412234', '79112412234'],
            'empty string' => ['', null],
            'non numeric' => ['abc', null],
            'null' => [null, null],
        ];
    }

    public function test_email_normalization(): void
    {
        $this->assertSame('ivan@example.com', ContactNormalizer::email(' Ivan@Example.COM '));
        $this->assertNull(ContactNormalizer::email(''));
        $this->assertNull(ContactNormalizer::email(null));
    }
}
