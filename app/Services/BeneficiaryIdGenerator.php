<?php

namespace App\Services;

use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;

class BeneficiaryIdGenerator
{
    private const SUFFIX_DIGITS = 4;

    /**
     * Generate a FIRST-MIDDLE-LAST-NNNN candidate for the given name.
     *
     * Uniqueness is ultimately enforced by the beneficiaries.beneficiary_code
     * unique constraint; callers retry via Beneficiary::createWithCode() on a
     * collision (offset widens the candidate suffix per attempt).
     */
    public function generate(string $first, ?string $middle, string $last, int $offset = 0): string
    {
        $base = NameNormalizer::codeName($first, $middle, $last);

        if ($base === '') {
            $base = 'BENEFICIARY';
        }

        $suffix = $this->maxSuffix($base) + 1 + max(0, $offset);

        return $base.'-'.str_pad((string) $suffix, self::SUFFIX_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Highest numeric suffix already used for the name prefix (0 when none).
     */
    public function maxSuffix(string $base): int
    {
        return DB::table('beneficiaries')
            ->where('beneficiary_code', 'like', $base.'-%')
            ->pluck('beneficiary_code')
            ->map(fn (string $code) => preg_match('/-(\d+)$/', $code, $matches) ? (int) $matches[1] : 0)
            ->max() ?? 0;
    }
}
