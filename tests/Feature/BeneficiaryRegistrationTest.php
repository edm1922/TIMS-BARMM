<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeneficiaryRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    public function test_create_form_contains_pre_registration_duplicate_modal(): void
    {
        $batch = $this->seededBatch(['name' => 'Registration Batch']);

        $this->actingAs($this->staff())->get('/batches/'.$batch->id.'/beneficiaries/create')
            ->assertOk()
            ->assertSee('Verify beneficiary')
            ->assertSee('id="duplicateCheckVerify"', false)
            ->assertSee(route('beneficiaries.duplicate-check'), false)
            ->assertSee('id="beneficiaryForm"', false)
            ->assertSee('data-ph-phone', false)
            ->assertSee('data-phone-hint', false)
            ->assertSee('name="employment_interest"', false)
            ->assertSee('data-bs-backdrop="static"', false)
            ->assertSee('data-bs-keyboard="false"', false)
            ->assertSee('Registration Batch')
            ->assertDontSee('Continue registration');
    }

    public function test_a_beneficiary_can_only_be_registered_inside_a_batch(): void
    {
        $staff = $this->staff();

        // The standalone creation page and endpoint are gone: a beneficiary is
        // registered from within the batch it belongs to.
        $this->actingAs($staff)->get('/beneficiaries/create')->assertNotFound();

        $this->actingAs($staff)->post('/beneficiaries', [
            'first_name' => 'ORPHAN',
            'last_name' => 'RECORD',
            'duplicate_check_done' => '1',
        ])->assertStatus(405);

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_store_requires_duplicate_check_to_be_completed(): void
    {
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Gate Batch']);

        $this->actingAs($this->staff())
            ->from('/batches/'.$batch->id.'/beneficiaries/create')
            ->post('/batches/'.$batch->id.'/beneficiaries', [
                'first_name' => 'GATE',
                'last_name' => 'CHECK',
                'address' => $location->full_address,
                'location_code' => $location->psgc_code,
            ])->assertSessionHasErrors(['duplicate_check_done']);

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_duplicate_check_returns_matches_for_existing_record(): void
    {
        $staff = $this->staff();

        Beneficiary::factory()->named('SAME', 'NAME', 'HERE')->withBirthdate('1990-01-01')->create();

        $response = $this->actingAs($staff)->postJson('/beneficiaries/duplicate-check', [
            'first_name' => 'same',
            'last_name' => 'here',
            'birthdate' => '1990-01-01',
        ]);

        $response->assertOk()
            ->assertJson(['duplicate' => true, 'rule' => 'name_birthdate'])
            ->assertJsonPath('matches.0.full_name', 'SAME NAME HERE');
    }

    public function test_duplicate_check_reports_no_match_for_new_person(): void
    {
        $response = $this->actingAs($this->staff())->postJson('/beneficiaries/duplicate-check', [
            'first_name' => 'BRAND',
            'last_name' => 'NEW',
            'birthdate' => '1988-03-03',
        ]);

        $response->assertOk()->assertJson(['duplicate' => false, 'matches' => []]);
    }

    public function test_duplicate_check_requires_name_and_birthdate(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/beneficiaries/duplicate-check', ['first_name' => 'ONLY'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['last_name', 'birthdate']);
    }

    public function test_contact_number_must_be_a_valid_philippine_number(): void
    {
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Phone Validation Batch']);

        $this->actingAs($this->staff())
            ->from('/batches/'.$batch->id.'/beneficiaries/create')
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
                'first_name' => 'INVALID',
                'last_name' => 'PHONE',
                'contact_no' => '0917123456',
            ]))
            ->assertSessionHasErrors('contact_no');

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_duplicate_check_does_not_create_records(): void
    {
        $this->actingAs($this->staff())->postJson('/beneficiaries/duplicate-check', [
            'first_name' => 'GHOST',
            'last_name' => 'RECORD',
            'birthdate' => '1991-01-01',
        ])->assertOk();

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_store_uppercases_text_and_normalizes_extension(): void
    {
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Uppercase Batch']);

        $this->actingAs($this->staff())->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
            'first_name' => '  maria  ',
            'middle_name' => 'santos',
            'last_name' => 'reyes',
            'extension_name' => 'jr',
            'birthdate' => '1996-07-22',
            'occupation' => 'vendor',
            'beneficiary_signify' => 'skills training',
            'beneficiary_type' => 'senior citizen',
            'mode_of_payment' => 'bank',
        ]));

        $beneficiary = Beneficiary::query()->firstOrFail();

        $this->assertSame('MARIA', $beneficiary->first_name);
        $this->assertSame('SANTOS', $beneficiary->middle_name);
        $this->assertSame('REYES', $beneficiary->last_name);
        $this->assertSame('JR.', $beneficiary->extension_name);
        $this->assertSame('VENDOR', $beneficiary->occupation);
        $this->assertSame('SKILLS TRAINING', $beneficiary->beneficiary_signify);
        $this->assertSame('SENIOR CITIZEN', $beneficiary->beneficiary_type);
        $this->assertSame('BANK', $beneficiary->mode_of_payment);
        $this->assertSame('MARIA SANTOS REYES JR.', $beneficiary->full_name);
        $this->assertSame($batch->id, $beneficiary->beneficiary_batch_id);
    }

    public function test_employment_interest_is_saved_from_registration(): void
    {
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Employment Interest Batch']);

        $this->actingAs($this->staff())->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
            'first_name' => 'INTEREST',
            'last_name' => 'CHECK',
            'employment_interest' => 'YES',
        ]));

        $beneficiary = Beneficiary::query()->firstOrFail();

        $this->assertSame('YES', $beneficiary->employment_interest);
    }

    public function test_employment_interest_must_be_yes_or_no(): void
    {
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Employment Interest Validation Batch']);

        $this->actingAs($this->staff())
            ->from('/batches/'.$batch->id.'/beneficiaries/create')
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
                'first_name' => 'INVALID',
                'last_name' => 'INTEREST',
                'employment_interest' => 'MAYBE',
            ]))
            ->assertSessionHasErrors('employment_interest');

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_store_keeps_other_detail_and_composes_labels(): void
    {
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Other Detail Batch']);

        $this->actingAs($this->staff())->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
            'first_name' => 'OTHER',
            'last_name' => 'DETAIL',
            'occupation' => 'OTHER',
            'occupation_other' => 'street sweeper',
            'id_type' => 'OTHERS',
            'id_type_other' => 'company id',
        ]));

        $beneficiary = Beneficiary::query()->firstOrFail();

        $this->assertSame('STREET SWEEPER', $beneficiary->occupation_other);
        $this->assertSame('OTHER — STREET SWEEPER', $beneficiary->occupation_label);
        $this->assertSame('COMPANY ID', $beneficiary->id_type_other);
        $this->assertSame('OTHERS — COMPANY ID', $beneficiary->id_type_label);
    }

    public function test_address_is_resolved_from_reference_data_not_free_text(): void
    {
        $location = $this->seededLocation([
            'psgc_code' => '1999905777',
            'barangay' => 'BALACAYON',
            'city_municipality' => 'PAHAMUDDIN',
            'province' => 'SPECIAL GEOGRAPHIC AREA',
            'full_address' => 'BALACAYON, PAHAMUDDIN, SPECIAL GEOGRAPHIC AREA',
        ]);
        $batch = $this->seededBatch(['name' => 'Address Batch']);

        $this->actingAs($this->staff())->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
            'first_name' => 'ADDRESS',
            'last_name' => 'CHECK',
            'address' => 'something the user typed',
        ]));

        $beneficiary = Beneficiary::query()->firstOrFail();

        $this->assertSame('BALACAYON, PAHAMUDDIN, SPECIAL GEOGRAPHIC AREA', $beneficiary->address);
        $this->assertSame('PAHAMUDDIN', $beneficiary->city_municipality);
        $this->assertSame('SPECIAL GEOGRAPHIC AREA', $beneficiary->province);
    }

    public function test_location_search_returns_barmm_barangays(): void
    {
        $this->seededLocation([
            'psgc_code' => '1999905888',
            'barangay' => 'DATU BINASING',
            'city_municipality' => 'PAHAMUDDIN',
            'province' => 'SPECIAL GEOGRAPHIC AREA',
            'full_address' => 'DATU BINASING, PAHAMUDDIN, SPECIAL GEOGRAPHIC AREA',
        ]);

        $this->actingAs($this->staff())->getJson('/locations/search?q=datu binasing')
            ->assertOk()
            ->assertJsonPath('results.0.psgc_code', '1999905888');
    }

    public function test_location_search_is_case_insensitive_and_guarded(): void
    {
        $this->seededLocation([
            'psgc_code' => '1999905999',
            'barangay' => 'TEST BARANGAY',
            'city_municipality' => 'TEST MUNICIPALITY',
            'province' => 'TEST PROVINCE',
            'full_address' => 'TEST BARANGAY, TEST MUNICIPALITY, TEST PROVINCE',
        ]);

        $this->actingAs($this->staff())->getJson('/locations/search?q=test barangay')
            ->assertOk()
            ->assertJsonCount(1, 'results');

        $this->actingAs($this->staff())->getJson('/locations/search?q=')
            ->assertOk()
            ->assertJsonCount(0, 'results');
    }

    public function test_location_search_requires_authentication(): void
    {
        $this->get('/locations/search?q=test')->assertRedirect('/login');
    }

    public function test_edit_form_shows_legacy_value_as_selected_option(): void
    {
        $beneficiary = Beneficiary::factory()->create([
            'occupation' => 'FARMER',
            'id_type' => "VOTER'S CERTIFICATE",
        ]);

        $this->actingAs($this->staff())->get('/beneficiaries/'.$beneficiary->id.'/edit')
            ->assertOk()
            ->assertSee('FARMER (legacy)')
            ->assertSee("VOTER'S CERTIFICATE (legacy)");
    }

    public function test_show_page_presents_new_fields_and_hides_epayment(): void
    {
        $beneficiary = Beneficiary::factory()->create([
            'address' => 'BALACAYON, PAHAMUDDIN, SPECIAL GEOGRAPHIC AREA',
            'beneficiary_signify' => 'LIVELIHOOD ASSISTANCE',
            'mode_of_payment' => 'DISKARTECH',
            'epayment_account_no' => 'Money Remittance',
        ]);

        $this->actingAs($this->staff())->get('/beneficiaries/'.$beneficiary->id)
            ->assertOk()
            ->assertSee('BALACAYON, PAHAMUDDIN, SPECIAL GEOGRAPHIC AREA')
            ->assertSee('LIVELIHOOD ASSISTANCE')
            ->assertSee('DISKARTECH')
            ->assertDontSee('ePayment account');
    }

    public function test_the_registration_page_is_organized_into_sections_with_a_sticky_submission_bar(): void
    {
        $batch = $this->seededBatch(['name' => 'Sectioned Registration Batch']);

        $response = $this->actingAs($this->staff())->get('/batches/'.$batch->id.'/beneficiaries/create')
            ->assertOk()
            ->assertSee('Add Beneficiary')
            ->assertSee('Register a new beneficiary under the selected batch.')
            ->assertSee('data-reg-section="identity"', false)
            ->assertSee('data-reg-section="address"', false)
            ->assertSee('data-reg-section="identification"', false)
            ->assertSee('data-reg-section="employment"', false)
            ->assertSee('data-reg-section="additional"', false)
            ->assertSee('Identity information')
            ->assertSee('Address information')
            ->assertSee('Identification &amp; contact', false)
            ->assertSee('Employment &amp; assistance', false)
            ->assertSee('Additional information')
            ->assertSee('data-reg-progress-count', false)
            ->assertSee('reg-actions-bar')
            ->assertSee('id="beneficiarySubmit"', false)
            ->assertSee('id="age_display"', false)
            ->assertSee('readonly', false)
            ->assertSee('data-age-display', false)
            ->assertSee('Verify beneficiary')
            ->assertSee('Back to batch');

        // Every original form control must still be submitted under the same name.
        foreach ([
            'first_name', 'middle_name', 'last_name', 'extension_name', 'birthdate', 'sex', 'civil_status',
            'address', 'location_code', 'id_type', 'source_id_no', 'cos_no', 'contact_no',
            'beneficiary_type', 'beneficiary_signify', 'occupation', 'mode_of_payment', 'avg_monthly_income',
            'dependent_name', 'dependent_contact_no', 'employment_interest', 'skills_training_needed',
            'duplicate_check_done',
        ] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_a_success_modal_is_shown_after_creating_a_beneficiary(): void
    {
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Success Modal Batch']);

        $this->actingAs($this->staff())->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
            'first_name' => 'SUCCESS',
            'last_name' => 'MODAL',
        ]))->assertRedirect(route('beneficiary-batches.show', $batch));

        $beneficiary = Beneficiary::query()->firstOrFail();

        $this->actingAs($this->staff())->get(route('beneficiary-batches.show', $batch))
            ->assertOk()
            ->assertSee('id="beneficiaryCreatedModal"', false)
            ->assertSee('Beneficiary created')
            ->assertSee($beneficiary->full_name)
            ->assertSee($beneficiary->beneficiary_code)
            ->assertSee('Success Modal Batch')
            ->assertSee('View beneficiary')
            ->assertSee('Add another')
            ->assertSee('beneficiaryCreatedModal');
    }
}
