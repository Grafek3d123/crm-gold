<?php

namespace App\Support;

enum InquirySourceKind: string
{
    case WebForm = 'web_form';
    case Call = 'call';
    case Email = 'email';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WebForm => 'Форма сайта',
            self::Call => 'Входящий звонок',
            self::Email => 'Письмо',
            self::Other => 'Другое',
        };
    }
}
