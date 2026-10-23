<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;

/**
 * Layered duplicate detection (brief §5). Never merges or overwrites —
 * only reports candidates for an operator to decide.
 */
class DuplicateDetector
{
    /**
     * @return array{rule: string, matches: Collection<int, Beneficiary>}|null
     *                                                                         null = no candidates at all
     */
    public function find(?string $beneficiaryCode, ?string $sourceIdNumber, ?string $sourceIdNo,
        ?string $first, ?string $middle, ?string $last, ?string $birthdateIso,
        ?int $ignoreId = null): ?array
    {
        $base = fn ($query) => $ignoreId ? $query->where('id', '!=', $ignoreId) : $query;

        // 1. exact application-generated code
        if ($beneficiaryCode !== null && $beneficiaryCode !== '') {
            $m = $base(Beneficiary::query())->where('beneficiary_code', $beneficiaryCode)->get();
            if ($m->isNotEmpty()) {
                return ['rule' => 'beneficiary_code', 'matches' => $m];
            }
        }

        // 2. exact source DOLE ID NUMBER
        if ($sourceIdNumber !== null && $sourceIdNumber !== '') {
            $m = $base(Beneficiary::query())->where('source_id_number', $sourceIdNumber)->get();
            if ($m->isNotEmpty()) {
                return ['rule' => 'source_id_number', 'matches' => $m];
            }
        }

        $nameKey = NameNormalizer::matchKey($first, $middle, $last);

        // 2b. exact source government ID No. (advisory: values repeat in the source)
        if ($sourceIdNo !== null && $sourceIdNo !== '') {
            $m = $base(Beneficiary::query())
                ->where('source_id_no', trim($sourceIdNo))
                ->get();
            if ($m->isNotEmpty()) {
                return ['rule' => 'source_id_no', 'matches' => $m];
            }
        }

        if ($nameKey === '||') {
            return null;
        }

        // 3. normalized name + valid birthdate
        if ($birthdateIso !== null && $birthdateIso !== '') {
            $m = $base(Beneficiary::query())
                ->where('name_key', $nameKey)
                ->whereDate('birthdate', $birthdateIso)
                ->get();
            if ($m->isNotEmpty()) {
                return ['rule' => 'name_birthdate', 'matches' => $m];
            }
        }

        // 4. name only — weak candidate, never auto-merged
        $m = $base(Beneficiary::query())->where('name_key', $nameKey)->get();

        if ($m->isNotEmpty()) {
            return ['rule' => 'name_only_weak', 'matches' => $m];
        }

        return null;
    }

    /**
     * Pre-registration check for the verification modal. First + last name are
     * required by the form; a blank middle name is treated as a wildcard so a
     * record with a recorded middle name is not missed.
     *
     * @return array{rule: string, matches: Collection<int, Beneficiary>}|null
     */
    public function findForRegistration(?string $first, ?string $middle, ?string $last, ?string $birthdateIso): ?array
    {
        $first = NameNormalizer::segment($first);
        $last = NameNormalizer::segment($last);
        $middle = NameNormalizer::segment($middle);

        if ($first === '' || $last === '') {
            return null;
        }

        // name_key is stored as FIRST|MIDDLE|LAST with hyphenated segments.
        $pattern = $middle !== ''
            ? $first.'|'.$middle.'|'.$last
            : $first.'|%|'.$last;

        $nameMatches = Beneficiary::query()
            ->where('name_key', 'like', $pattern)
            ->get();

        if ($nameMatches->isEmpty()) {
            return null;
        }

        if ($birthdateIso !== null && $birthdateIso !== '') {
            $withBirthdate = $nameMatches
                ->filter(fn (Beneficiary $b) => $b->birthdate?->toDateString() === $birthdateIso)
                ->values();

            if ($withBirthdate->isNotEmpty()) {
                return ['rule' => 'name_birthdate', 'matches' => $withBirthdate];
            }
        }

        return ['rule' => 'name_only_weak', 'matches' => $nameMatches];
    }

    /**
     * Convenience wrapper taking a model (without persisting changes).
     *
     * @return array{rule: string, matches: Collection<int, Beneficiary>}|null
     */
    public function findFor(Beneficiary $subject, ?int $ignoreId = null): ?array
    {
        return $this->find(
            $subject->beneficiary_code,
            $subject->source_id_number,
            $subject->source_id_no,
            $subject->first_name,
            $subject->middle_name,
            $subject->last_name,
            $subject->birthdate?->toDateString(),
            $ignoreId,
        );
    }

    /**
     * Weak (name-only) matches must be labelled as such for the operator.
     */
    public static function ruleLabel(string $rule): string
    {
        return match ($rule) {
            'beneficiary_code' => 'Same beneficiary ID',
            'source_id_number' => 'Same DOLE ID NUMBER',
            'source_id_no' => 'Same government ID No.',
            'name_birthdate' => 'Same name and birthdate',
            'name_only_weak' => 'Same name only (weak match)',
            default => $rule,
        };
    }
}
