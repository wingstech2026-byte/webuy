<?php

namespace App\Support;

/**
 * Sierra Leone phone numbers.
 *
 * Stored as 232 + 8 digits (23276123456). Accepts 076123456, 76123456,
 * +232 76 123 456, 00232 76 123 456 and displays as 076 123 456.
 */
final class PhoneNumber
{
    public const COUNTRY_CODE = '232';

    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        if ($digits === '' || $digits === null) {
            return null;
        }

        if (str_starts_with($digits, '00232')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, self::COUNTRY_CODE)) {
            $national = substr($digits, 3);
            // People often write +232 076 123 456
            if (strlen($national) === 9 && str_starts_with($national, '0')) {
                $national = substr($national, 1);
            }
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } else {
            $national = $digits;
        }

        if (! preg_match('/^[1-9]\d{7}$/', $national)) {
            return null;
        }

        return self::COUNTRY_CODE.$national;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /** 23276123456 => 076 123 456 */
    public static function display(?string $phone): string
    {
        $normalized = self::normalize($phone);

        if ($normalized === null) {
            return (string) $phone;
        }

        $national = substr($normalized, 3);

        return '0'.substr($national, 0, 2).' '.substr($national, 2, 3).' '.substr($national, 5, 3);
    }
}
