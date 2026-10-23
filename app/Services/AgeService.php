<?php

namespace App\Services;

use Carbon\CarbonInterface;

class AgeService
{
    /**
     * Age in whole years from a valid past birthdate; null when missing or invalid.
     */
    public static function age(mixed $birthdate): ?int
    {
        if (! $birthdate instanceof CarbonInterface) {
            return null;
        }

        if ($birthdate->isFuture()) {
            return null;
        }

        return (int) $birthdate->age;
    }

    /**
     * adult (>=18) | underage (<18) | unknown (missing/invalid/future birthdate)
     */
    public static function status(mixed $birthdate): string
    {
        $age = self::age($birthdate);

        if ($age === null) {
            return 'unknown';
        }

        return $age < 18 ? 'underage' : 'adult';
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'underage' => 'Underage',
            'adult' => 'Adult',
            default => 'Needs Review',
        };
    }
}
