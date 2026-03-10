<?php

namespace App\Support;

class VenezuelanPhoneFormatter
{
    public static function toWati(?string $phone): ?string
    {
        $subscriber = self::extractVenezuelanSubscriber($phone);

        return $subscriber ? '+58' . $subscriber : null;
    }

    public static function toOdoo(?string $phone): ?string
    {
        $subscriber = self::extractVenezuelanSubscriber($phone);

        return $subscriber ? '0' . $subscriber : null;
    }

    private static function extractVenezuelanSubscriber(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '58') && strlen($digits) === 12) {
            $digits = substr($digits, 2) ?: '';
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1) ?: '';
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        return $digits;
    }
}
