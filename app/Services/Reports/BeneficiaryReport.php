<?php

namespace App\Services\Reports;

use App\Models\Beneficiary;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared filter/column definitions for the beneficiary reports page and its
 * CSV/XLSX exports, so the on-screen list and the exported file always agree.
 * Every option mirrors docs/source-data-mapping.md field names.
 */
class BeneficiaryReport
{
    public const STATUSES = ['active', 'archived', 'all'];

    public const AGE_STATUSES = ['adult', 'underage', 'unknown'];

    public const DUPLICATE_STATUSES = ['none', 'candidate', 'confirmed_duplicate', 'cleared'];

    public const UNDERAGE_YEARS = 18;

    /**
     * @return array{q: string, status: string, age_status: string, duplicate_status: string, created_from: ?string, created_to: ?string}
     */
    public static function filters(array $input): array
    {
        $status = (string) ($input['status'] ?? 'active');
        $age = (string) ($input['age_status'] ?? '');
        $duplicate = (string) ($input['duplicate_status'] ?? '');

        return [
            'q' => trim((string) ($input['q'] ?? '')),
            'status' => in_array($status, self::STATUSES, true) ? $status : 'active',
            'age_status' => in_array($age, self::AGE_STATUSES, true) ? $age : '',
            'duplicate_status' => in_array($duplicate, self::DUPLICATE_STATUSES, true) ? $duplicate : '',
            'created_from' => self::dateOrNull($input['created_from'] ?? null),
            'created_to' => self::dateOrNull($input['created_to'] ?? null),
        ];
    }

    public static function query(array $filters): Builder
    {
        $query = Beneficiary::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');

        match ($filters['status']) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        if ($filters['q'] !== '') {
            $query->search($filters['q']);
        }

        self::applyAgeFilter($query, $filters['age_status']);

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
            'import_batch_id' => $beneficiary->import_batch_id,
            'archived_at' => $beneficiary->archived_at?->format('Y-m-d H:i'),
            'notes' => $beneficiary->notes,
            'created_at' => $beneficiary->created_at?->format('Y-m-d H:i'),
            'updated_at' => $beneficiary->updated_at?->format('Y-m-d H:i'),
        ];

        return array_map(fn ($value) => $value === null ? '' : (string) $value, $values);
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
