<?php
declare(strict_types=1);

namespace Fortpesa;

/** Kenyan mobile number helper. */
final class Phone
{
    private const PATTERN = '/^(?:\+?254|0)([17]\d{8})$/';

    public static function isValid(string $phone): bool
    {
        return (bool) preg_match(self::PATTERN, self::strip($phone));
    }

    /**
     * Normalise 0712345678, 0112345678, 254712345678 or +254712345678 to 2547XXXXXXXX / 2541XXXXXXXX.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(string $phone): string
    {
        if (!preg_match(self::PATTERN, self::strip($phone), $m)) {
            throw new \InvalidArgumentException('Invalid Kenyan phone number: expected 07XXXXXXXX, 01XXXXXXXX, 2547XXXXXXXX or +2547XXXXXXXX.');
        }
        return '254' . $m[1];
    }

    private static function strip(string $phone): string
    {
        return (string) preg_replace('/[\s\-().]/', '', $phone);
    }
}
