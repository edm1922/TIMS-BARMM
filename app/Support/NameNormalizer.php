<?php

namespace App\Support;

use Illuminate\Support\Str;

class NameNormalizer
{
    /**
     * Normalize a name fragment for beneficiary codes and duplicate matching:
     * transliterate accents, uppercase, collapse separators, trim hyphens.
     */
    public static function segment(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || strcasecmp($value, 'N/A') === 0) {
            return '';
        }

        $value = Str::ascii($value);
        $value = mb_strtoupper($value, 'UTF-8');
        $value = preg_replace('/[^A-Z0-9]+/u', '-', $value);
        $value = trim((string) $value, '-');

        return $value;
    }

    /**
     * Build the name portion of a beneficiary code: FIRST-MIDDLE-LAST
     * (middle omitted when absent; extensions excluded).
     */
    public static function codeName(string $first, ?string $middle, string $last, int $maxSegments = 3): string
    {
        $segments = array_values(array_filter([
            self::segment($first),
            self::segment($middle),
            self::segment($last),
        ], fn ($s) => $s !== ''));

        $segments = array_slice($segments, 0, $maxSegments);
        $code = implode('-', $segments);

        if (mb_strlen($code) > 60) {
            $code = mb_substr($code, 0, 60);
            $trimmed = rtrim($code, '-');

            // avoid cutting a segment in half when possible
            if (str_contains(mb_substr($code, -1), '-')) {
                $code = $trimmed;
            } elseif (strrpos($code, '-') !== false) {
                $code = mb_substr($code, 0, strrpos($code, '-'));
            }
        }

        return trim($code, '-');
    }

    /**
     * Normalized key used for duplicate matching: FIRST|MIDDLE|LAST
     */
    public static function matchKey(?string $first, ?string $middle, ?string $last): string
    {
        return implode('|', [
            self::segment($first),
            self::segment($middle),
            self::segment($last),
        ]);
    }
}
