<?php

namespace App\Services\Reports;

use App\Models\Beneficiary;
use App\Services\DuplicateDetector;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared filter/column definitions for the beneficiary reports page and its
 * CSV/XLSX exports, so the on-screen list and the exported file always agree.
 * Every option mirrors docs/source-data-mapping.md field names.
 */
class BeneficiaryReport
{
    public const STATUSES = ['active', 'archived', 'all', 'missing'];

    public const AGE_STATUSES = ['adult', 'underage', 'unknown'];

    public const AGE_RANGES = ['below18', '18-25', '26-31', '32-40', '41-50', '51-60', '61plus', 'unclassified'];

    public const SORTS = ['registered', 'newest', 'name', 'name_desc'];

    public const DUPLICATE_STATUSES = ['none', 'candidate', 'confirmed_duplicate', 'cleared'];

    public const UNDERAGE_YEARS = 18;

    /**
     * @return array{q: string, status: string, batch: string, age_status: string, duplicate_status: string, created_from: ?string, created_to: ?string}
     */
    public static function filters(array $input): array
    {
        $status = (string) ($input['status'] ?? 'active');
        $age = (string) ($input['age_status'] ?? '');
        $duplicate = (string) ($input['duplicate_status'] ?? '');
        $batch = trim((string) ($input['batch'] ?? ''));

        $ageRange = (string) ($input['age_range'] ?? '');
        $sex = strtoupper(trim((string) ($input['sex'] ?? '')));
        $civilStatus = trim((string) ($input['civil_status'] ?? ''));
        $sort = (string) ($input['sort'] ?? 'name');

        return [
            'q' => trim((string) ($input['q'] ?? '')),
            'status' => in_array($status, self::STATUSES, true) ? $status : 'active',
            'batch' => $batch === 'unassigned' || ctype_digit($batch) ? $batch : '',
            'age_status' => in_array($age, self::AGE_STATUSES, true) ? $age : '',
            'age_range' => in_array($ageRange, self::AGE_RANGES, true) ? $ageRange : '',
            'sex' => in_array($sex, ['MALE', 'M', 'FEMALE', 'F'], true) ? $sex : '',
            'civil_status' => $civilStatus,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'name',
            'duplicate_status' => in_array($duplicate, self::DUPLICATE_STATUSES, true) ? $duplicate : '',
            'created_from' => self::dateOrNull($input['created_from'] ?? null),
            'created_to' => self::dateOrNull($input['created_to'] ?? null),
        ];
    }

    public static function query(array $filters): Builder
    {
        $query = Beneficiary::query();

        match ($filters['sort']) {
            'registered' => $query->orderBy('id'),
            'newest' => $query->orderByDesc('id'),
            'name_desc' => $query->orderByDesc('last_name')->orderByDesc('first_name')->orderByDesc('id'),
            default => $query->orderBy('last_name')->orderBy('first_name')->orderBy('id'),
        };

        match ($filters['status']) {
            'archived' => $query->archived(),
            'all' => null,
            'missing' => $query->active()->where(function ($missing): void {
                $missing->whereNotNull('missing_fields')
                    ->orWhere(fn ($gov) => $gov->whereNull('source_id_no')->orWhere('source_id_no', ''))
                    ->orWhereNull('birthdate')
                    ->orWhereNull('sex')
                    ->orWhereNull('civil_status');
            }),
            default => $query->active(),
        };

        if ($filters['q'] !== '') {
            $query->search($filters['q']);
        }

        if ($filters['batch'] === 'unassigned') {
            $query->unassigned();
        } elseif (ctype_digit($filters['batch'])) {
            $query->inBatch((int) $filters['batch']);
        }

        self::applyAgeFilter($query, $filters['age_status']);

        if (($filters['age_range'] ?? '') !== '') {
            self::applyAgeRangeFilter($query, (string) $filters['age_range']);
        }

        if (($filters['sex'] ?? '') !== '') {
            $query->whereIn('sex', in_array($filters['sex'], ['MALE', 'M'], true) ? ['MALE', 'M'] : ['FEMALE', 'F']);
        }

        if (($filters['civil_status'] ?? '') !== '') {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $filters['civil_status']))));

            if ($statuses !== []) {
                $query->whereIn('civil_status', $statuses);
            }
        }

        if ($filters['duplicate_status'] !== '') {
            $query->where('duplicate_status', $filters['duplicate_status']);
        }

        if ($filters['created_from'] !== null) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if ($filters['created_to'] !== null) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        return $query;
    }

    /**
     * Single province / municipality / barangay covered by the filtered
     * report. Used for the printed DOLE form masthead: a mixed selection
     * leaves the value blank instead of claiming one location.
     *
     * @return array{province: ?string, municipality: ?string, barangay: ?string}
     */
    public static function locationScope(Builder $query): array
    {
        $values = [];

        foreach (['province', 'city_municipality', 'barangay'] as $column) {
            $distinct = (clone $query)
                ->reorder()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->distinct()
                ->limit(2)
                ->pluck($column);

            $values[$column] = $distinct->count() === 1 ? (string) $distinct->first() : null;
        }

        return [
            'province' => $values['province'],
            'municipality' => $values['city_municipality'],
            'barangay' => $values['barangay'],
        ];
    }

    private static function applyAgeRangeFilter(Builder $query, string $ageRange): void
    {
        if ($ageRange === 'unclassified') {
            $query->where(function (Builder $q): void {
                $q->whereNull('birthdate')->orWhereDate('birthdate', '>', now()->toDateString());
            });

            return;
        }

        $bounds = [
            'below18' => [null, 18],
            '18-25' => [18, 26],
            '26-31' => [26, 32],
            '32-40' => [32, 41],
            '41-50' => [41, 51],
            '51-60' => [51, 61],
            '61plus' => [61, 150],
        ][$ageRange] ?? null;

        if ($bounds === null) {
            return;
        }

        $query->whereNotNull('birthdate')->whereDate('birthdate', '<=', now()->toDateString());

        if ($bounds[0] !== null) {
            $query->whereDate('birthdate', '<=', now()->subYears($bounds[0])->toDateString());
        }

        $query->whereDate('birthdate', '>', now()->subYears($bounds[1])->toDateString());
    }

    private static function applyAgeFilter(Builder $query, string $ageStatus): void
    {
        $today = now()->toDateString();
        $cutoff = now()->subYears(self::UNDERAGE_YEARS)->toDateString();

        match ($ageStatus) {
            'underage' => $query->whereNotNull('birthdate')->whereDate('birthdate', '>', $cutoff),
            'adult' => $query->whereNotNull('birthdate')->whereDate('birthdate', '<=', $cutoff),
            'unknown' => $query->where(fn (Builder $q) => $q
                ->whereNull('birthdate')
                ->orWhereDate('birthdate', '>', $today)),
            default => null,
        };
    }

    /**
     * Column definitions used by the table and both export formats.
     *
     * @return array<string, string>
     */
    public static function columns(): array
    {
        return [
            'beneficiary_code' => 'Beneficiary ID',
            'source_id_number' => 'Source ID No.',
            'cos_no' => 'COS No.',
            'first_name' => 'First name',
            'middle_name' => 'Middle name',
            'last_name' => 'Last name',
            'extension_name' => 'Extension',
            'birthdate' => 'Birthdate',
            'age' => 'Computed age',
            'age_status' => 'Age status',
            'sex' => 'Sex',
            'civil_status' => 'Civil status',
            'address' => 'Address',
            'contact_no' => 'Contact no.',
            'beneficiary_type' => 'Beneficiary type',
            'beneficiary_signify' => 'Beneficiary signify',
            'occupation' => 'Occupation',
            'mode_of_payment' => 'Mode of payment',
            'avg_monthly_income' => 'Avg. monthly income',
            'dependent_name' => 'Dependent name',
            'employment_interest' => 'Employment interest',
            'skills_training_needed' => 'Skills training needed',
            'duplicate_status' => 'Duplicate status',
            'duplicate_rule' => 'Duplicate reason',
            'import_batch_id' => 'Import batch',
            'archived_at' => 'Archived at',
            'notes' => 'Notes',
            'created_at' => 'Created at',
            'updated_at' => 'Updated at',
        ];
    }

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return array_values(self::columns());
    }

    /**
     * @return list<string>
     */
    public static function row(Beneficiary $beneficiary): array
    {
        $values = [
            'beneficiary_code' => $beneficiary->beneficiary_code,
            'source_id_number' => $beneficiary->source_id_number,
            'cos_no' => $beneficiary->cos_no,
            'first_name' => $beneficiary->first_name,
            'middle_name' => $beneficiary->middle_name,
            'last_name' => $beneficiary->last_name,
            'extension_name' => $beneficiary->extension_name,
            'birthdate' => $beneficiary->birthdate?->format('Y-m-d'),
            'age' => $beneficiary->computedAge(),
            'age_status' => $beneficiary->ageStatus(),
            'sex' => $beneficiary->sex,
            'civil_status' => $beneficiary->civil_status,
            'address' => $beneficiary->display_address,
            'contact_no' => $beneficiary->contact_no,
            'beneficiary_type' => $beneficiary->beneficiary_type,
            'beneficiary_signify' => $beneficiary->beneficiary_signify,
            'occupation' => $beneficiary->occupation_label,
            'mode_of_payment' => $beneficiary->mode_of_payment,
            'avg_monthly_income' => $beneficiary->avg_monthly_income,
            'dependent_name' => $beneficiary->dependent_name,
            'employment_interest' => $beneficiary->employment_interest,
            'skills_training_needed' => $beneficiary->skills_training_needed,
            'duplicate_status' => $beneficiary->duplicate_status,
            'duplicate_rule' => $beneficiary->duplicate_rule !== null
                ? DuplicateDetector::ruleLabel($beneficiary->duplicate_rule)
                : null,
            'import_batch_id' => $beneficiary->import_batch_id,
            'archived_at' => $beneficiary->archived_at?->format('Y-m-d H:i'),
            'notes' => $beneficiary->notes,
            'created_at' => $beneficiary->created_at?->format('Y-m-d H:i'),
            'updated_at' => $beneficiary->updated_at?->format('Y-m-d H:i'),
        ];

        return array_map(fn ($value) => $value === null ? '' : (string) $value, $values);
    }

    /**
     * Filename in the DOLE workbook convention, e.g.
     * "TUPAD PROFILE OF BENEFICIARIES-DATU PAGLAS BARMM (83).xlsx".
     * When the export spans several municipalities the selected batch's name
     * supplies the area ("DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)" ->
     * "DATU PAGLAS"); otherwise the segment is omitted.
     *
     * @param  array{province?: ?string, municipality?: ?string, barangay?: ?string}  $location
     */
    public static function exportFilename(array $location, int $count, string $extension, ?string $batchName = null): string
    {
        $municipality = mb_strtoupper(
            self::filenamePart(self::locationWithBatch($location, $batchName)['municipality'] ?? null),
        );

        $name = 'TUPAD PROFILE OF BENEFICIARIES';

        if ($municipality !== '') {
            $name .= '-'.$municipality.' BARMM';
        }

        return $name.' ('.$count.').'.$extension;
    }

    /**
     * Fill a blank municipality from the selected batch's name so the printed
     * form and the filename agree when the batch's members span towns.
     *
     * @param  array{province?: ?string, municipality?: ?string, barangay?: ?string}  $location
     * @return array{province?: ?string, municipality?: ?string, barangay?: ?string}
     */
    public static function locationWithBatch(array $location, ?string $batchName): array
    {
        if (($location['municipality'] ?? null) === null && $batchName !== null) {
            $area = self::batchArea($batchName);

            if ($area !== '') {
                $location['municipality'] = $area;
            }
        }

        return $location;
    }

    /**
     * Leading town/city from a batch name, minus any export-title prefix and
     * member count: "DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)" ->
     * "DATU PAGLAS".
     */
    private static function batchArea(string $name): string
    {
        $area = preg_replace(
            '/^\s*(?:(?:tupad\s+)?profile\s+of\s+tupad\s+beneficiaries)\s*[-–—:]\s*/i',
            '',
            $name,
        ) ?? $name;

        $parts = preg_split('/\s*[,\(]/', $area);

        return mb_strtoupper(self::filenamePart($parts[0] ?? $area));
    }

    private static function filenamePart(?string $value): string
    {
        $value = preg_replace('/[\\\\\\/:*?"<>|]+/', ' ', (string) $value) ?? '';
        $value = preg_replace('/\\s+/', ' ', $value) ?? '';

        return trim($value);
    }

    /**
     * Headline counts for the reports landing page.
     *
     * @return array{total: int, active: int, archived: int, underage: int, unknown_age: int, duplicate_candidates: int}
     */
    public static function summary(): array
    {
        $adultCutoff = now()->subYears(self::UNDERAGE_YEARS)->toDateString();

        return [
            'total' => Beneficiary::query()->count(),
            'active' => Beneficiary::query()->active()->count(),
            'archived' => Beneficiary::query()->archived()->count(),
            'underage' => Beneficiary::query()->active()
                ->whereNotNull('birthdate')
                ->whereDate('birthdate', '>', $adultCutoff)
                ->count(),
            'unknown_age' => Beneficiary::query()->active()
                ->where(fn (Builder $q) => $q->whereNull('birthdate')
                    ->orWhereDate('birthdate', '>', now()->toDateString()))
                ->count(),
            'duplicate_candidates' => Beneficiary::query()
                ->where('duplicate_status', 'candidate')
                ->count(),
        ];
    }

    private static function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
