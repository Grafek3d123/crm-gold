<?php

namespace App\Enums;

enum InquiryStatus: string
{
    case New = 'new';
    case Qualified = 'qualified';
    case Converted = 'converted';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новый',
            self::Qualified => 'Квалифицирован',
            self::Converted => 'Переведён',
            self::Rejected => 'Отклонён',
            self::Duplicate => 'Дубликат',
            self::Archived => 'Архив',
        };
    }

    public function transitions(): array
    {
        return match ($this) {
            self::New => [self::Qualified, self::Converted, self::Rejected, self::Duplicate, self::Archived],
            self::Qualified => [self::Converted, self::Rejected, self::Duplicate, self::Archived],
            self::Converted => [self::Archived],
            self::Rejected => [self::Archived],
            self::Duplicate => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }
}