<?php

namespace App\Enums;

enum SupportedLocale: string
{
    case English = 'en';
    case French = 'fr';
    case German = 'de';
    case Italian = 'it';
    case Portuguese = 'pt';
    case Spanish = 'es';
    case Russian = 'ru';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $locale): string => $locale->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::French => 'French',
            self::German => 'German',
            self::Italian => 'Italian',
            self::Portuguese => 'Portuguese',
            self::Spanish => 'Spanish',
            self::Russian => 'Russian',
        };
    }
}
