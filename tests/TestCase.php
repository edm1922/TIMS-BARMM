<?php

namespace Tests;

use App\Models\BeneficiaryBatch;
use App\Models\Location;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A deterministic BARMM location row for tests. The real reference data is
     * seeded from the PSGC snapshot; tests must never depend on real data, so
     * this creates a clearly fabricated row on demand.
     */
    protected function seededLocation(array $attributes = []): Location
    {
        return Location::query()->firstOrCreate(
            ['psgc_code' => $attributes['psgc_code'] ?? '1999905999'],
            array_merge([
                'barangay' => 'TEST BARANGAY',
                'city_municipality' => 'TEST MUNICIPALITY',
                'province' => 'TEST PROVINCE',
                'full_address' => 'TEST BARANGAY, TEST MUNICIPALITY, TEST PROVINCE',
                'is_city' => false,
            ], $attributes),
        );
    }

    /**
     * A fabricated release batch. Beneficiaries are registered inside a batch,
     * so every registration test needs one.
     */
    protected function seededBatch(array $attributes = []): BeneficiaryBatch
    {
        return BeneficiaryBatch::query()->firstOrCreate(
            ['name' => $attributes['name'] ?? 'Test Batch'],
            array_merge([
                'description' => 'Fabricated batch for tests.',
                'status' => BeneficiaryBatch::STATUS_OPEN,
                'created_by' => null,
            ], $attributes),
        );
    }

    /**
     * A complete, valid beneficiary payload for the current registration form.
     *
     * @return array<string, mixed>
     */
    protected function beneficiaryPayload(Location $location, array $attributes = []): array
    {
        return array_merge([
            'first_name' => 'TEST',
            'middle_name' => null,
            'last_name' => 'BENEFICIARY',
            'extension_name' => 'NONE',
            'birthdate' => '1990-01-01',
            'id_type' => "VOTER'S ID",
            'source_id_no' => 'TEST-GOV-ID-0001',
            'contact_no' => '09000000000',
            'beneficiary_type' => 'SENIOR CITIZEN',
            'beneficiary_signify' => 'SKILLS TRAINING',
            'mode_of_payment' => 'BANK',
            'occupation' => 'VENDOR',
            'sex' => 'FEMALE',
            'civil_status' => 'SINGLE',
            'avg_monthly_income' => 5000,
            'dependent_name' => 'NONE',
            'dependent_contact_no' => '09000000000',
            'address' => $location->full_address,
            'location_code' => $location->psgc_code,
            'duplicate_check_done' => '1',
        ], $attributes);
    }
}
