<?php

namespace App\Support;

use App\Models\BeneficiaryOptionValue;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled vocabularies for beneficiary registration.
 *
 * Legacy values that are not part of these lists are preserved on existing
 * records and surfaced to operators; they are never silently remapped.
 */
final class BeneficiaryOptions
{
    public const OCCUPATION_OTHER = 'OTHER';

    public const ID_TYPE_OTHER = 'OTHERS';

    public const EXTENSION_NONE = 'NONE';

    /**
     * Runtime-added option values, grouped by field.
     *
     * @var array<string, array<string, string>>|null
     */
    private static ?array $extraOptions = null;

    /**
     * Fields whose controlled vocabulary can be extended at runtime.
     *
     * @return list<string>
     */
    public static function editableFields(): array
    {
        return ['occupation', 'id_type', 'beneficiary_signify', 'beneficiary_type', 'mode_of_payment'];
    }

    /**
     * Human label for an option field.
     */
    public static function fieldLabel(string $field): string
    {
        return [
            'occupation' => 'Occupation',
            'id_type' => 'Type of ID',
            'beneficiary_signify' => 'Beneficiary signify',
            'beneficiary_type' => 'Type of beneficiary',
            'mode_of_payment' => 'Mode of payment',
        ][$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Canonical option lists merged with administrator-added values.
     *
     * @return array<string, string> value => label
     */
    public static function occupations(): array
    {
        return self::mergeOptions(self::baseOccupations(), 'occupation');
    }

    /**
     * @return array<string, string> value => label
     */
    public static function idTypes(): array
    {
        return self::mergeOptions(self::baseIdTypes(), 'id_type');
    }

    /**
     * @return array<string, string> value => label
     */
    public static function benefifySignify(): array
    {
        return self::mergeOptions(self::baseBenefifySignify(), 'beneficiary_signify');
    }

    /**
     * @return array<string, string> value => label
     */
    public static function beneficiaryTypes(): array
    {
        return self::mergeOptions(self::baseBeneficiaryTypes(), 'beneficiary_type');
    }

    /**
     * @return array<string, string> value => label
     */
    public static function modesOfPayment(): array
    {
        return self::mergeOptions(self::baseModesOfPayment(), 'mode_of_payment');
    }

    /**
     * @return array<string, string> value => label
     */
    public static function extensions(): array
    {
        return self::mergeOptions(self::baseExtensions(), 'extension_name');
    }

    /**
     * @param  array<string, string>  $base
     * @return array<string, string>
     */
    private static function mergeOptions(array $base, string $field): array
    {
        foreach (self::extrasFor($field) as $value => $label) {
            if (! array_key_exists($value, $base)) {
                $base[$value] = $label;
            }
        }

        return $base;
    }

    /**
     * @return array<string, string>
     */
    private static function extrasFor(string $field): array
    {
        self::loadExtras();

        return self::$extraOptions[$field] ?? [];
    }

    private static function loadExtras(): void
    {
        if (self::$extraOptions !== null) {
            return;
        }

        self::$extraOptions = [];

        if (! Schema::hasTable('beneficiary_option_values')) {
            return;
        }

        foreach (BeneficiaryOptionValue::query()->orderBy('value')->get() as $row) {
            self::$extraOptions[$row->field][$row->value] = $row->value;
        }
    }

    /**
     * Clear the per-request cache after a new option is added.
     */
    public static function flushExtraCache(): void
    {
        self::$extraOptions = null;
    }

    /**
     * @return array<string, string> value => label
     */
    private static function baseOccupations(): array
    {
        return [
            'TRANSPORT WORKER' => 'TRANSPORT WORKER',
            'VENDOR' => 'VENDOR',
            'CROP GROWER' => 'CROP GROWER',
            'HOMEBASED WORKER (I.E SEWER)' => 'HOMEBASED WORKER (I.E SEWER)',
            'FISHERFOLK' => 'FISHERFOLK',
            'LIVESTOCK/POULTRY RAISER' => 'LIVESTOCK/POULTRY RAISER',
            'SMALL TRANSPORT DRIVER' => 'SMALL TRANSPORT DRIVER',
            'LABORER' => 'LABORER',
            'OTHER' => 'OTHER (please specify)',
        ];
    }

    /**
     * @return array<string, string> value => label
     */
    private static function baseIdTypes(): array
    {
        return [
            "VOTER'S ID" => "VOTER'S ID",
            'DRIVER LICENSE' => "DRIVER'S LICENSE",
            'PAG-IBIG' => 'PAG-IBIG',
            'POSTAL ID' => 'POSTAL ID',
            'PRC ID' => 'PRC ID',
            'PASSPORT' => 'PASSPORT',
            'UNIFIED MULTI-PURPOSE ID (UMID)' => 'UNIFIED MULTI-PURPOSE ID (UMID)',
            'SENIOR CITIZEN ID' => 'SENIOR CITIZEN ID',
            'NATIONAL ID' => 'NATIONAL ID',
            'TIN ID' => 'TIN ID',
            'PHILSYS ID' => 'PHILSYS ID',
            'PHILHEALTH' => 'PHILHEALTH',
            'BARANGAY ID' => 'BARANGAY ID',
            'BARANGAY CLEARANCE' => 'BARANGAY CLEARANCE',
            'NBI CLEARANCE' => 'NBI CLEARANCE',
            'POLICE CLEARANCE' => 'POLICE CLEARANCE',
            'OTHERS' => 'OTHERS (please specify)',
        ];
    }

    /**
     * @return array<string, string> value => label
     */
    private static function baseBenefifySignify(): array
    {
        return [
            'SKILLS TRAINING' => 'SKILLS TRAINING',
            'EMPLOYMENT FACILITATION ASSISTANCE' => 'EMPLOYMENT FACILITATION ASSISTANCE',
            'LIVELIHOOD ASSISTANCE' => 'LIVELIHOOD ASSISTANCE',
            'NONE' => 'NONE',
        ];
    }

    /**
     * @return array<string, string> value => label
     */
    private static function baseBeneficiaryTypes(): array
    {
        return [
            'BALIK PROBINSYA BAGONG PAG-ASA BENEFICIARIES' => 'BALIK PROBINSYA BAGONG PAG-ASA BENEFICIARIES',
            'DISPLACED WORKER DUE TO COVID 19' => 'DISPLACED WORKER DUE TO COVID 19',
            'DISPLACED WORKER DUE TO NATURAL CALAMITY' => 'DISPLACED WORKER DUE TO NATURAL CALAMITY',
            'DISPLACED WORKER DUE TO CLOSURE OF COMPANY' => 'DISPLACED WORKER DUE TO CLOSURE OF COMPANY',
            'DEPENDENTS OF KILLED-IN-ACTION (KIA)' => 'DEPENDENTS OF KILLED-IN-ACTION (KIA)',
            'DEPENDENTS OF KILLED-IN-POLICE-OPERATION (KIPO)' => 'DEPENDENTS OF KILLED-IN-POLICE-OPERATION (KIPO)',
            'DEPENDENTS OF WOUNDED-IN-ACTION (WIA)' => 'DEPENDENTS OF WOUNDED-IN-ACTION (WIA)',
            'DEPENDENTS OF WOUNDED-IN-POLICE-OPERATION (WIPO)' => 'DEPENDENTS OF WOUNDED-IN-POLICE-OPERATION (WIPO)',
            'FORMER REBEL' => 'FORMER REBEL',
            'FORMER VIOLENT EXTREMIST GROUP' => 'FORMER VIOLENT EXTREMIST GROUP',
            'INDIGENOUS PERSON' => 'INDIGENOUS PERSON',
            'PERSON WITH DISABILITY (PWD)' => 'PERSON WITH DISABILITY (PWD)',
            'SENIOR CITIZEN' => 'SENIOR CITIZEN',
            'UNDEREMPLOYED/SELF-EMPLOYED WORKERS' => 'UNDEREMPLOYED/SELF-EMPLOYED WORKERS',
            'WORKERS IN THE PRIORITY AREAS UNDER THE NTF-ELCAC' => 'WORKERS IN THE PRIORITY AREAS UNDER THE NTF-ELCAC',
            'PARENTS OF CHILD LABORER' => 'PARENTS OF CHILD LABORER',
        ];
    }

    /**
     * @return array<string, string> value => label
     */
    private static function baseModesOfPayment(): array
    {
        return [
            'BANK' => 'BANK',
            'DISKARTECH' => 'DISKARTECH',
            'MONEY REMITTANCE DIRECT PAYMENT' => 'MONEY REMITTANCE DIRECT PAYMENT',
        ];
    }

    /**
     * Extension dropdown, including on ID cards.
     *
     * @return array<string, string> value => label
     */
    private static function baseExtensions(): array
    {
        return [
            'NONE' => 'NONE',
            'JR.' => 'JR.',
            'SR.' => 'SR.',
            'II' => 'II',
            'III' => 'III',
            'IV' => 'IV',
        ];
    }

    /**
     * Allowed values for a field, used by FormRequest validation.
     *
     * @return list<string>
     */
    public static function values(string $field): array
    {
        $map = [
            'occupation' => self::occupations(),
            'id_type' => self::idTypes(),
            'beneficiary_signify' => self::benefifySignify(),
            'beneficiary_type' => self::beneficiaryTypes(),
            'mode_of_payment' => self::modesOfPayment(),
            'extension_name' => self::extensions(),
        ];

        return array_keys($map[$field] ?? []);
    }

    /**
     * Human label for a stored value, including legacy values outside the list.
     */
    public static function label(string $field, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $options = [
            'occupation' => self::occupations(),
            'id_type' => self::idTypes(),
            'beneficiary_signify' => self::benefifySignify(),
            'beneficiary_type' => self::beneficiaryTypes(),
            'mode_of_payment' => self::modesOfPayment(),
        ][$field] ?? [];

        return $options[$value] ?? $value;
    }

    /**
     * True when the stored value is not part of the controlled list.
     */
    public static function isLegacy(string $field, ?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return ! in_array($value, self::values($field), true);
    }

    /**
     * Known government ID number formats, per ID type. The single source of
     * truth for both the browser's live masking (the view exposes this map as
     * JSON) and the server-side normalization before storing.
     *
     * pattern — characters to KEEP (everything else is stripped)
     * groups  — separator positions, as character counts per group
     *
     * @return array<string, array{pattern: string, groups: list<int>, uppercase: bool}>
     */
    public static function idNumberMasks(): array
    {
        return [
            'NATIONAL ID' => ['pattern' => '[^0-9]', 'groups' => [4, 4, 4, 4], 'uppercase' => false],
            'PHILSYS ID' => ['pattern' => '[^0-9]', 'groups' => [4, 4, 4, 4], 'uppercase' => false],
            'PHILHEALTH' => ['pattern' => '[^0-9]', 'groups' => [2, 9, 1], 'uppercase' => false],
            'TIN ID' => ['pattern' => '[^0-9]', 'groups' => [3, 3, 3, 3], 'uppercase' => false],
        ];
    }

    /**
     * Apply the known format for an ID type to a Government ID No. value.
     * Unknown ID types and blank values pass through untouched, so OTHERS
     * (free text) and legacy data are never mangled.
     */
    public static function formatIdNumber(?string $idType, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $mask = self::idNumberMasks()[mb_strtoupper(trim((string) $idType))] ?? null;

        if ($mask === null) {
            return $value;
        }

        $cleaned = preg_replace('/'.$mask['pattern'].'/u', '', $value) ?? '';

        if ($cleaned === '') {
            return '';
        }

        if ($mask['uppercase']) {
            $cleaned = mb_strtoupper($cleaned);
        }

        $parts = [];
        $position = 0;

        foreach ($mask['groups'] as $size) {
            if ($position >= mb_strlen($cleaned)) {
                break;
            }

            $parts[] = mb_substr($cleaned, $position, $size);
            $position += $size;
        }

        if ($position < mb_strlen($cleaned)) {
            // Preserve anything beyond the defined groups rather than
            // truncating operator input.
            $parts[] = mb_substr($cleaned, $position);
        }

        return implode('-', $parts);
    }
}
