<?php

namespace App\Services\Import;

/**
 * Source-column ↔ DB-field vocabulary. Labels follow the workbook headers
 * (docs/source-data-mapping.md §3).
 */
final class FieldMap
{
    /**
     * Selectable target fields (empty = ignored).
     *
     * @var array<string, string>
     */
    public const OPTIONS = [
        '' => '— ignore —',
        'beneficiary_code' => 'Beneficiary ID (only if the file already contains app codes)',
        'source_id_number' => 'ID NUMBER (DOLE source key)',
        'source_row_no' => 'No. (source row sequence)',
        'first_name' => 'First Name',
        'middle_name' => 'Middle Name',
        'last_name' => 'Last Name',
        'extension_name' => 'Extension Name',
        'birthdate' => 'Birthdate',
        'barangay' => 'Brgy.',
        'city_municipality' => 'City/Municipality',
        'province' => 'Province',
        'district' => 'District',
        'address' => 'Address (full, optional)',
        'id_type' => 'Type of ID',
        'id_type_other' => 'Type of ID — specify (when OTHERS)',
        'source_id_no' => 'ID No. (government ID)',
        'contact_no' => 'Contact No.',
        'epayment_account_no' => 'E-payment/Account No.',
        'beneficiary_type' => 'Type of Beneficiary',
        'beneficiary_signify' => 'Beneficiary Signify',
        'mode_of_payment' => 'Mode of Payment',
        'occupation' => 'Occupation',
        'occupation_other' => 'Occupation — specify (when OTHER)',
        'sex' => 'Sex',
        'civil_status' => 'Civil Status',
        'age_source' => 'Age (source only, never authoritative)',
        'avg_monthly_income' => 'Average monthly income',
        'dependent_name' => 'Dependent',
        'employment_interest' => 'Interested in wage employment or self-employment?',
        'skills_training_needed' => 'Skills Training Needed',
    ];

    /**
     * Default matching for the inspected workbook's two-row header
     * (group label " / " sub-header, normalized).
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'id number' => 'source_id_number',
        'no.' => 'source_row_no',
        'name of beneficiary / first name' => 'first_name',
        'name of beneficiary / middle name' => 'middle_name',
        'name of beneficiary / last name' => 'last_name',
        'name of beneficiary / extension name' => 'extension_name',
        'birthdate / (yyyy/mm/dd)' => 'birthdate',
        'project location / brgy.' => 'barangay',
        'project location / city/municipality' => 'city_municipality',
        'project location / province' => 'province',
        'project location / district' => 'district',
        'type of id' => 'id_type',
        'id no.' => 'source_id_no',
        'contact no.' => 'contact_no',
        'e-payment/account no.' => 'epayment_account_no',
        'type of beneficiary' => 'beneficiary_type',
        'beneficiary signify' => 'beneficiary_signify',
        'mode of payment' => 'mode_of_payment',
        'occupation' => 'occupation',
        'sex' => 'sex',
        'civil status' => 'civil_status',
        'age' => 'age_source',
        'average monthly income' => 'avg_monthly_income',
        'dependent' => 'dependent_name',
        'skills training needed' => 'skills_training_needed',
    ];

    /**
     * Single-row-header fallbacks (sub-label used alone).
     *
     * @var array<string, string>
     */
    private const SINGLE_ROW = [
        'first name' => 'first_name',
        'middle name' => 'middle_name',
        'last name' => 'last_name',
        'extension name' => 'extension_name',
        'birthdate' => 'birthdate',
        'brgy.' => 'barangay',
        'city/municipality' => 'city_municipality',
        'province' => 'province',
        'district' => 'district',
    ];

    public static function normalizeLabel(string $label): string
    {
        $label = mb_strtolower($label);
        $label = str_replace(["\r", "\n", "\t"], ' ', $label);
        $label = preg_replace('/\s+/u', ' ', trim($label)) ?? $label;

        return $label;
    }

    /**
     * @return string field name or '' when unrecognized
     */
    public static function defaultFor(string $rawLabel): string
    {
        $label = self::normalizeLabel($rawLabel);

        if (str_starts_with($label, 'interested in wage employment')) {
            return 'employment_interest';
        }

        if (isset(self::DEFAULTS[$label])) {
            return self::DEFAULTS[$label];
        }

        if (isset(self::SINGLE_ROW[$label])) {
            return self::SINGLE_ROW[$label];
        }

        return '';
    }
}
