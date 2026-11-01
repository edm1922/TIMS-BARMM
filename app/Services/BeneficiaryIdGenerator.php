<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Support\SyncIdentity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BeneficiaryIdGenerator
{
    private const PREFIX = 'DOLE XII-SCFO-BARMM';

    private const SERIES_DIGITS = 5;

    private const MONTH_YEAR_FORMAT = 'mY';

    private const SUFFIX_LENGTH = 4;

    /**
     * Generate a DOLE XII-SCFO-BARMM-{MMYYYY}-NNNNN candidate.
     * Series resets monthly based on the current MMYYYY.
     *
     * An offline installation appends a short marker of its own server to the
     * code (DOLE XII-SCFO-BARMM-102026-00001-A1B2), so a code it issues can
     * never be the same code the Central Server issued to another person. The
     * Central Server keeps the plain format, because its codes are the
     * authoritative ones printed on the ID cards.
     *
     * Uniqueness is ultimately enforced by the beneficiaries.beneficiary_code
     * unique constraint; callers retry via Beneficiary::createWithCode() on a
     * collision (offset widens the candidate suffix per attempt).
     */
    public function generate(string $first, ?string $middle, string $last, int $offset = 0): string
    {
        $monthYear = date(self::MONTH_YEAR_FORMAT);
        $base = self::PREFIX.'-'.$monthYear;

        $suffix = $this->maxSuffix($base) + 1 + max(0, $offset);

        $code = $base.'-'.str_pad((string) $suffix, self::SERIES_DIGITS, '0', STR_PAD_LEFT);

        $serverSuffix = $this->serverSuffix();

        return $serverSuffix === null ? $code : $code.'-'.$serverSuffix;
    }

    /**
     * Return the next code from the batch's administrator-defined series, or
     * null when the batch has no custom series. The batch's stored series is
     * advanced atomically; codes already in use are skipped.
     */
    public function generateForBatch(?int $batchId): ?string
    {
        if ($batchId === null) {
            return null;
        }

        return DB::transaction(function () use ($batchId): ?string {
            $batch = BeneficiaryBatch::query()->lockForUpdate()->find($batchId);
            $series = trim((string) ($batch?->beneficiary_code_series ?? ''));

            if ($batch === null || $series === '') {
                return null;
            }

            $candidate = $series;
            $guard = 0;

            while (Beneficiary::query()->where('beneficiary_code', $candidate)->exists()) {
                $candidate = $this->incrementSeries($candidate);

                if (++$guard > 10000) {
                    throw new RuntimeException('Could not allocate a beneficiary ID from the batch series: too many codes are already in use.');
                }
            }

            $batch->forceFill(['beneficiary_code_series' => $this->incrementSeries($candidate)])->save();

            return $candidate;
        });
    }

    /**
     * Increment the trailing numeric block of a code, preserving its width
     * (01646 → 01647, 99999 → 100000).
     */
    public function incrementSeries(string $code): string
    {
        if (preg_match('/^(.*?)(\d+)$/s', trim($code), $matches) !== 1) {
            throw new RuntimeException('The ID series must end with a number so it can increment.');
        }

        $prefix = $matches[1];
        $digits = $matches[2];
        $next = (string) ((int) $digits + 1);
        $width = strlen($digits);

        if (strlen($next) < $width) {
            $next = str_pad($next, $width, '0', STR_PAD_LEFT);
        }

        return $prefix.$next;
    }

    /**
     * A custom series must be a non-empty code ending with digits.
     */
    public static function isValidSeries(string $value): bool
    {
        $value = trim($value);

        return $value !== ''
            && mb_strlen($value) <= 64
            && preg_match('/^.{1,63}\d+$/s', $value) === 1;
    }

    /**
     * The short marker of this installation, or null on the Central Server.
     */
    public function serverSuffix(): ?string
    {
        if (! SyncIdentity::isOffline()) {
            return null;
        }

        $uuid = SyncIdentity::originUuid();

        if (! is_string($uuid) || trim($uuid) === '') {
            return null;
        }

        return strtoupper(substr(str_replace('-', '', $uuid), 0, self::SUFFIX_LENGTH));
    }

    /**
     * Highest numeric suffix already used for the current MMYYYY prefix (0 when none).
     */
    private function maxSuffix(string $base): int
    {
        return DB::table('beneficiaries')
            ->where('beneficiary_code', 'like', $base.'-%')
            ->pluck('beneficiary_code')
            ->map(fn (string $code): int => $this->seriesNumber($code, $base))
            ->max() ?? 0;
    }

    /**
     * The series number of a code, ignoring the optional server marker.
     */
    private function seriesNumber(string $code, string $base): int
    {
        $remainder = substr($code, strlen($base) + 1);
        $number = strtok($remainder, '-');

        return $number === false ? 0 : (int) $number;
    }
}
