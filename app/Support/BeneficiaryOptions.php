<?php

namespace App\Support;

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
     * @return array<string, string> value => label
     */
    public static function occupations(): array
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
    public static function idTypes(): array
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
    public static function benefifySignify(): array
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
    public static function beneficiaryTypes(): array
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
    public static function modesOfPayment(): array
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
    public static function extensions(): array
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
}
