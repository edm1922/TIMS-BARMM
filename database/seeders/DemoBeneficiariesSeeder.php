<?php

namespace Database\Seeders;

use App\Models\Beneficiary;
use Illuminate\Database\Seeder;

class DemoBeneficiariesSeeder extends Seeder
{
    /**
     * Fabricated demo records for local development and tests.
     * Clearly marked in `notes`; never real beneficiary data.
     */
    public function run(): void
    {
        $demo = 'DEMO RECORD — fabricated data for development/testing; not a real beneficiary.';

        if (Beneficiary::query()->where('notes', 'like', 'DEMO RECORD%')->exists()) {
            $this->command?->info('Demo beneficiaries already present — skipped.');

            return;
        }

        $records = [
            [
                'first_name' => 'JUAN', 'middle_name' => 'ANDRES', 'last_name' => 'DELA CRUZ',
                'birthdate' => '1990-01-15', 'sex' => 'MALE', 'civil_status' => 'MARRIED',
                'barangay' => 'DEMO BARANGAY 1', 'city_municipality' => 'DEMO TOWN',
                'province' => 'DEMO PROVINCE', 'district' => 'LONE',
                'address' => 'DEMO BARANGAY 1, DEMO TOWN, DEMO PROVINCE',
                'id_type' => 'NATIONAL ID', 'source_id_no' => 'DEMO-0001-0001',
                'contact_no' => '09170000001', 'occupation' => 'CROP GROWER',
                'beneficiary_type' => 'UNDEREMPLOYED/SELF-EMPLOYED WORKERS',
                'beneficiary_signify' => 'LIVELIHOOD ASSISTANCE',
                'mode_of_payment' => 'DISKARTECH',
                'avg_monthly_income' => 4500, 'skills_training_needed' => 'Agriculture Crops Production',
            ],
            [
                'first_name' => 'JUAN', 'middle_name' => 'B', 'last_name' => 'DELA CRUZ',
                'birthdate' => '1985-11-30', 'sex' => 'MALE', 'civil_status' => 'SINGLE',
                'barangay' => 'DEMO BARANGAY 1', 'city_municipality' => 'DEMO TOWN',
                'province' => 'DEMO PROVINCE', 'district' => 'LONE',
                'address' => 'DEMO BARANGAY 1, DEMO TOWN, DEMO PROVINCE',
                'occupation' => 'OTHER', 'occupation_other' => 'BOAT OPERATOR',
                'id_type' => 'OTHERS', 'id_type_other' => 'DOLE REGISTRATION SLIP',
                'beneficiary_type' => 'INDIGENOUS PERSON',
                'beneficiary_signify' => 'EMPLOYMENT FACILITATION ASSISTANCE',
                'mode_of_payment' => 'BANK',
                'avg_monthly_income' => 3000,
            ],
            [
                'first_name' => 'MARIA', 'middle_name' => 'SANTOS', 'last_name' => 'REYES',
                'birthdate' => '1996-07-22', 'sex' => 'FEMALE', 'civil_status' => 'WIDOW',
                'barangay' => 'DEMO BARANGAY 2', 'city_municipality' => 'DEMO TOWN',
                'province' => 'DEMO PROVINCE', 'district' => 'LONE',
                'occupation' => 'VENDOR', 'beneficiary_type' => 'SELF-EMPLOYED/UNDEREMPLOYED',
                'avg_monthly_income' => 5000,
            ],
            [
                'first_name' => 'TEST', 'middle_name' => 'MINOR', 'last_name' => 'UNDERAGE',
                'birthdate' => '2010-05-01', 'sex' => 'MALE', 'civil_status' => 'SINGLE',
                'barangay' => 'DEMO BARANGAY 2', 'city_municipality' => 'DEMO TOWN',
                'province' => 'DEMO PROVINCE', 'district' => 'LONE',
                'occupation' => 'STUDENT', 'beneficiary_type' => 'SELF-EMPLOYED/UNDEREMPLOYED',
                'notes' => $demo.' (intentionally underage for status testing)',
            ],
            [
                'first_name' => 'NO', 'middle_name' => null, 'last_name' => 'BIRTHDATE',
                'birthdate' => null, 'birthdate_raw' => 'N/A', 'sex' => 'FEMALE',
                'civil_status' => 'MARRIED', 'barangay' => 'DEMO BARANGAY 3',
                'city_municipality' => 'DEMO TOWN', 'province' => 'DEMO PROVINCE', 'district' => 'LONE',
                'occupation' => 'LABORER', 'beneficiary_type' => 'SELF-EMPLOYED/UNDEREMPLOYED',
                'notes' => $demo.' (intentionally missing birthdate for Needs Review testing)',
            ],
            [
                'first_name' => 'MIDDLE', 'middle_name' => null, 'last_name' => 'ABSENT',
                'birthdate' => '1978-03-09', 'sex' => 'MALE', 'civil_status' => 'MARRIED',
                'barangay' => 'DEMO BARANGAY 1', 'city_municipality' => 'DEMO TOWN',
                'province' => 'DEMO PROVINCE', 'district' => 'LONE',
                'occupation' => 'DRIVER', 'beneficiary_type' => 'SELF-EMPLOYED/UNDEREMPLOYED',
                'avg_monthly_income' => 3800,
            ],
            [
                'first_name' => 'ARCHIVED', 'middle_name' => 'DEMO', 'last_name' => 'RECORD',
                'birthdate' => '1992-12-01', 'sex' => 'FEMALE', 'civil_status' => 'SEPARATED',
                'barangay' => 'DEMO BARANGAY 3', 'city_municipality' => 'DEMO TOWN',
                'province' => 'DEMO PROVINCE', 'district' => 'LONE',
                'occupation' => 'VENDOR', 'beneficiary_type' => 'SELF-EMPLOYED/UNDEREMPLOYED',
                'archived_at' => now(), 'archive_reason' => 'Demo archived record',
                'notes' => $demo,
            ],
            [
                'first_name' => 'SPACE', 'middle_name' => ' PADDING ', 'last_name' => '  TRAILING ',
                'birthdate' => '2000-02-29', 'sex' => 'MALE', 'civil_status' => 'SINGLE',
                'barangay' => 'DEMO BARANGAY 2', 'city_municipality' => 'DEMO TOWN',
                'province' => 'DEMO PROVINCE', 'district' => 'LONE',
                'occupation' => 'ONLINE SELLER', 'beneficiary_type' => 'SELF-EMPLOYED/UNDEREMPLOYED',
                'avg_monthly_income' => 3000,
                'notes' => $demo.' (intentionally stored with whitespace for normalization testing)',
            ],
        ];

        foreach ($records as $record) {
            if (! array_key_exists('notes', $record)) {
                $record['notes'] = $demo;
            }

            $record['source_id_number'] = null;

            Beneficiary::createWithCode($record);
        }
    }
}
