<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeneficiaryCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    public function test_guest_is_redirected_to_login_from_index(): void
    {
        $this->get('/beneficiaries')->assertRedirect('/login');
    }

    public function test_inactive_user_is_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get('/beneficiaries')->assertForbidden();
    }

    public function test_index_lists_active_by_default_and_archived_when_filtered(): void
    {
        $user = $this->staff();

        $active = Beneficiary::factory()->named('ACTIVE', null, 'RECORD')->create();
        $archived = Beneficiary::factory()->named('ARCHIVED', null, 'RECORD')->create();
        $archived->archive('moved out');

        $this->actingAs($user)->get('/beneficiaries')
            ->assertOk()
            ->assertSee($active->beneficiary_code)
            ->assertDontSee($archived->beneficiary_code);

        $this->actingAs($user)->get('/beneficiaries?status=archived')
            ->assertOk()
            ->assertSee($archived->beneficiary_code)
            ->assertDontSee($active->beneficiary_code);

        $this->actingAs($user)->get('/beneficiaries?status=all')
            ->assertOk()
            ->assertSee($active->beneficiary_code)
            ->assertSee($archived->beneficiary_code);
    }

    public function test_records_filter_exposes_missing_fields_and_hides_removed_options(): void
    {
        $user = $this->staff();

        $complete = Beneficiary::factory()->named('COMPLETE', null, 'RECORD')->create([
            'source_id_no' => 'COMPLETE-GOV-1',
            'birthdate' => '1990-01-01',
            'sex' => 'MALE',
            'civil_status' => 'SINGLE',
        ]);

        $missing = Beneficiary::factory()->named('INCOMPLETE', null, 'RECORD')->create([
            'source_id_no' => null,
        ]);

        // The Flag dropdown and the Archived radio are gone.
        $this->actingAs($user)->get('/beneficiaries')
            ->assertOk()
            ->assertSee('Missing fields')
            ->assertDontSee('Archived')
            ->assertDontSee('Flag')
            ->assertSee($complete->beneficiary_code)
            ->assertSee($missing->beneficiary_code);

        $this->actingAs($user)->get('/beneficiaries?status=missing')
            ->assertOk()
            ->assertSee($missing->beneficiary_code)
            ->assertDontSee($complete->beneficiary_code);
    }

    public function test_index_searches_by_name_code_and_barangay(): void
    {
        $user = $this->staff();

        $target = Beneficiary::factory()->named('FINDME', null, 'NOWHERE')
            ->create(['barangay' => 'Poblacion']);

        $other = Beneficiary::factory()->named('OTHER', null, 'PERSON')
            ->create(['barangay' => 'Maligaya']);

        $this->actingAs($user)->get('/beneficiaries?q=FINDME')
            ->assertOk()
            ->assertSee($target->beneficiary_code)
            ->assertDontSee($other->beneficiary_code);

        $this->actingAs($user)->get('/beneficiaries?q=FINDME+NOWHERE')
            ->assertOk()
            ->assertSee($target->beneficiary_code)
            ->assertDontSee($other->beneficiary_code);

        $this->actingAs($user)->get('/beneficiaries?q=NOWHERE+FINDME')
            ->assertOk()
            ->assertSee($target->beneficiary_code)
            ->assertDontSee($other->beneficiary_code);

        $this->actingAs($user)->get('/beneficiaries?q=FINDME+Poblacion')
            ->assertOk()
            ->assertSee($target->beneficiary_code)
            ->assertDontSee($other->beneficiary_code);

        $this->actingAs($user)->get('/beneficiaries?q=Maligaya')
            ->assertOk()
            ->assertSee($other->beneficiary_code)
            ->assertDontSee($target->beneficiary_code);
    }

    public function test_index_lists_beneficiaries_in_registration_order_by_default(): void
    {
        $user = $this->staff();

        $first = Beneficiary::factory()->named('ZARA', null, 'ZAMORA')->create();
        $second = Beneficiary::factory()->named('ALMA', null, 'ABAD')->create();
        $third = Beneficiary::factory()->named('MIA', null, 'CRUZ')->create();

        // Registration order (oldest first), not alphabetical.
        $this->actingAs($user)->get('/beneficiaries')
            ->assertOk()
            ->assertSeeInOrder([$first->beneficiary_code, $second->beneficiary_code, $third->beneficiary_code]);

        // Alphabetical remains available as an explicit option.
        $this->actingAs($user)->get('/beneficiaries?sort=name')
            ->assertOk()
            ->assertSeeInOrder(['ALMA ABAD', 'MIA CRUZ', 'ZARA ZAMORA']);

        // Newest-first option.
        $this->actingAs($user)->get('/beneficiaries?sort=newest')
            ->assertOk()
            ->assertSeeInOrder([$third->beneficiary_code, $second->beneficiary_code, $first->beneficiary_code]);
    }

    public function test_index_filters_by_age_status(): void
    {
        $user = $this->staff();

        $adult = Beneficiary::factory()->named('GROWN', null, 'UP')->withBirthdate('1990-01-01')->create();
        $minor = Beneficiary::factory()->named('YOUNG', null, 'ONE')->underage()->create();

        $this->actingAs($user)->get('/beneficiaries?age_status=underage')
            ->assertOk()
            ->assertSee($minor->beneficiary_code)
            ->assertDontSee($adult->beneficiary_code);

        $this->actingAs($user)->get('/beneficiaries?age_status=adult')
            ->assertOk()
            ->assertSee($adult->beneficiary_code)
            ->assertDontSee($minor->beneficiary_code);
    }

    public function test_index_exposes_the_age_distribution_filter(): void
    {
        $user = $this->staff();

        $thirties = Beneficiary::factory()->named('THIRTY', null, 'SOMETHING')
            ->withBirthdate(now()->subYears(30)->toDateString())->create();
        $sixties = Beneficiary::factory()->named('SIXTIES', null, 'SOMETHING')
            ->withBirthdate(now()->subYears(65)->toDateString())->create();

        $response = $this->actingAs($user)->get('/beneficiaries');

        $response->assertOk()
            ->assertSee('Age distribution')
            ->assertSee('18–25 years')
            ->assertSee('61 years and above')
            ->assertSee('Unclassified');

        $this->actingAs($user)->get('/beneficiaries?age_range=26-31')
            ->assertOk()
            ->assertSee($thirties->beneficiary_code)
            ->assertDontSee($sixties->beneficiary_code);

        $this->actingAs($user)->get('/beneficiaries?age_range=61plus')
            ->assertOk()
            ->assertSee($sixties->beneficiary_code)
            ->assertDontSee($thirties->beneficiary_code);
    }

    public function test_store_creates_record_with_generated_code_and_audit(): void
    {
        $user = $this->staff();

        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'CRUD Batch']);

        $payload = $this->beneficiaryPayload($location, [
            'first_name' => 'jane',
            'middle_name' => 'm',
            'last_name' => 'doe',
            'birthdate' => '1990-05-05',
            'sex' => 'female',
            'civil_status' => 'single',
        ]);

        $response = $this->actingAs($user)->post('/batches/'.$batch->id.'/beneficiaries', $payload);

        $beneficiary = Beneficiary::query()->firstOrFail();

        $response->assertRedirect(route('beneficiary-batches.show', $batch));
        $this->assertSame('DOLE XII-SCFO-BARMM-'.now()->format('mY').'-00001', $beneficiary->beneficiary_code);
        $this->assertSame('JANE', $beneficiary->first_name);
        $this->assertSame($location->full_address, $beneficiary->address);
        $this->assertSame($location->psgc_code, $beneficiary->location_code);
        $this->assertSame($location->barangay, $beneficiary->barangay);
        $this->assertSame($location->city_municipality, $beneficiary->city_municipality);
        $this->assertSame('none', $beneficiary->duplicate_status);
        $this->assertSame($batch->id, $beneficiary->beneficiary_batch_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary.created',
            'entity_type' => 'beneficiary',
            'entity_id' => $beneficiary->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_store_rejects_address_outside_the_reference_list(): void
    {
        $user = $this->staff();
        $batch = $this->seededBatch(['name' => 'Address Reject Batch']);

        $response = $this->actingAs($user)->from('/batches/'.$batch->id.'/beneficiaries/create')
            ->post('/batches/'.$batch->id.'/beneficiaries', [
                'first_name' => 'NO',
                'last_name' => 'LOCATION',
                'address' => 'SOMEWHERE ELSE',
                'duplicate_check_done' => '1',
            ]);

        $response->assertSessionHasErrors(['address']);
        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_store_requires_other_detail_for_free_text_options(): void
    {
        $user = $this->staff();
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Other Detail Batch']);

        $response = $this->actingAs($user)->from('/batches/'.$batch->id.'/beneficiaries/create')
            ->post('/batches/'.$batch->id.'/beneficiaries', [
                'first_name' => 'MISSING',
                'last_name' => 'DETAIL',
                'address' => $location->full_address,
                'location_code' => $location->psgc_code,
                'occupation' => 'OTHER',
                'id_type' => 'OTHERS',
                'duplicate_check_done' => '1',
            ]);

        $response->assertSessionHasErrors(['occupation_other', 'id_type_other']);
    }

    public function test_store_rejects_values_outside_the_controlled_lists(): void
    {
        $user = $this->staff();
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Bad Values Batch']);

        $response = $this->actingAs($user)->from('/batches/'.$batch->id.'/beneficiaries/create')
            ->post('/batches/'.$batch->id.'/beneficiaries', [
                'first_name' => 'BAD',
                'last_name' => 'VALUES',
                'address' => $location->full_address,
                'location_code' => $location->psgc_code,
                'occupation' => 'ASTRONAUT',
                'mode_of_payment' => 'CRYPTO',
                'duplicate_check_done' => '1',
            ]);

        $response->assertSessionHasErrors(['occupation', 'mode_of_payment']);
    }

    public function test_update_preserves_a_legacy_value_but_blocks_new_ones(): void
    {
        $admin = $this->admin();
        $location = $this->seededLocation();

        $beneficiary = Beneficiary::factory()->create(['occupation' => 'FARMER']);

        // Unchanged legacy value is accepted on update.
        $this->actingAs($admin)->put('/beneficiaries/'.$beneficiary->id, $this->beneficiaryPayload($location, [
            'first_name' => $beneficiary->first_name,
            'last_name' => $beneficiary->last_name,
            'occupation' => 'FARMER',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('FARMER', $beneficiary->refresh()->occupation);

        // A brand new out-of-list value is rejected.
        $this->actingAs($admin)->from('/beneficiaries/'.$beneficiary->id.'/edit')
            ->put('/beneficiaries/'.$beneficiary->id, $this->beneficiaryPayload($location, [
                'first_name' => $beneficiary->first_name,
                'last_name' => $beneficiary->last_name,
                'occupation' => 'SPACEMAN',
            ]))->assertSessionHasErrors(['occupation']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->staff();
        $batch = $this->seededBatch(['name' => 'Required Fields Batch']);

        $response = $this->actingAs($user)->from('/batches/'.$batch->id.'/beneficiaries/create')
            ->post('/batches/'.$batch->id.'/beneficiaries', ['first_name' => 'NO']);

        $response->assertSessionHasErrors(['last_name']);
        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_update_changes_fields_but_never_the_generated_code(): void
    {
        $user = $this->staff();
        $location = $this->seededLocation();

        $beneficiary = Beneficiary::factory()->named('OLD', null, 'NAME')->create();
        $originalCode = $beneficiary->beneficiary_code;

        $this->actingAs($user)->put('/beneficiaries/'.$beneficiary->id, $this->beneficiaryPayload($location, [
            'first_name' => 'NEW',
            'middle_name' => null,
            'last_name' => 'NAME',
            'birthdate' => '1985-12-31',
            'sex' => 'MALE',
            'civil_status' => 'MARRIED',
        ]))->assertRedirect(route('beneficiaries.show', $beneficiary));

        $beneficiary->refresh();

        $this->assertSame($originalCode, $beneficiary->beneficiary_code);
        $this->assertSame('NEW', $beneficiary->first_name);
        $this->assertSame('1985-12-31', $beneficiary->birthdate->toDateString());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary.updated',
            'entity_id' => $beneficiary->id,
        ]);
    }

    public function test_admin_can_archive_and_restore(): void
    {
        $admin = $this->admin();

        $beneficiary = Beneficiary::factory()->create();

        $this->actingAs($admin)->post('/beneficiaries/'.$beneficiary->id.'/archive', [
            'archive_reason' => 'moved away',
        ])->assertRedirect('/beneficiaries/'.$beneficiary->id);

        $beneficiary->refresh();
        $this->assertNotNull($beneficiary->archived_at);
        $this->assertSame('moved away', $beneficiary->archive_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'beneficiary.archived', 'entity_id' => $beneficiary->id]);

        $this->actingAs($admin)->post('/beneficiaries/'.$beneficiary->id.'/unarchive')
            ->assertRedirect('/beneficiaries/'.$beneficiary->id);

        $this->assertNull($beneficiary->refresh()->archived_at);
    }

    public function test_staff_cannot_archive(): void
    {
        $staff = $this->staff();
        $beneficiary = Beneficiary::factory()->create();

        $this->actingAs($staff)->post('/beneficiaries/'.$beneficiary->id.'/archive')
            ->assertForbidden();

        $this->assertNull($beneficiary->refresh()->archived_at);
    }

    public function test_duplicate_resolve_is_admin_only_and_audited(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $beneficiary = Beneficiary::factory()->create();

        $this->actingAs($staff)->post('/beneficiaries/'.$beneficiary->id.'/duplicate-resolve', [
            'decision' => 'cleared',
            'note' => 'same person typo',
        ])->assertForbidden();

        $this->actingAs($admin)->post('/beneficiaries/'.$beneficiary->id.'/duplicate-resolve', [
            'decision' => 'confirmed_duplicate',
            'note' => 'same person as CODE-0002',
        ])->assertRedirect('/beneficiaries/'.$beneficiary->id);

        $beneficiary->refresh();
        $this->assertSame('confirmed_duplicate', $beneficiary->duplicate_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary.duplicate_resolved',
            'entity_id' => $beneficiary->id,
        ]);
    }

    public function test_show_page_renders_record_and_badges(): void
    {
        $user = $this->staff();

        $beneficiary = Beneficiary::factory()
            ->named('SHOW', 'CASE', 'PAGE')
            ->underage()
            ->create(['employment_interest' => 'YES']);

        $this->actingAs($user)->get('/beneficiaries/'.$beneficiary->id)
            ->assertOk()
            ->assertSee($beneficiary->beneficiary_code)
            ->assertSee('SHOW CASE PAGE')
            ->assertSee('Underage')
            ->assertSee('YES')
            ->assertDontSee('Age (source)')
            ->assertDontSee('DOLE ID NUMBER (source)');
    }

    public function test_show_page_escapes_user_supplied_fields(): void
    {
        $user = $this->staff();
        $payload = '<script>alert("xss")</script>';

        $beneficiary = Beneficiary::factory()->create(['dependent_name' => $payload]);

        $this->actingAs($user)->get('/beneficiaries/'.$beneficiary->id)
            ->assertOk()
            ->assertDontSee($payload, false)
            ->assertSee(e($payload), false);
    }

    public function test_the_beneficiary_index_uses_the_shared_dashboard_layout(): void
    {
        $user = $this->staff();

        Beneficiary::factory()->count(3)->create();

        $this->actingAs($user)->get('/beneficiaries')
            ->assertOk()
            ->assertSee('Search &amp; filter beneficiaries', false)
            ->assertSee('Beneficiary records')
            ->assertSee('Active records')
            ->assertSee('Missing fields')
            ->assertSee('Rows per page')
            ->assertDontSee('Apply filters')
            ->assertDontSee('Clear filters')
            ->assertDontSee('Archived');
    }

    public function test_the_beneficiary_index_supports_page_size_selection(): void
    {
        $user = $this->staff();

        Beneficiary::factory()->count(30)->create();

        $response = $this->actingAs($user)->get('/beneficiaries?per_page=50&status=all')->assertOk();

        $this->assertSame(50, $response->viewData('perPage'));
        $this->assertSame(30, $response->viewData('beneficiaries')->count());

        $fallback = $this->actingAs($user)->get('/beneficiaries?per_page=999')->assertOk();
        $this->assertSame(25, $fallback->viewData('perPage'));
    }

    public function test_the_beneficiary_detail_page_uses_the_shared_workspace_layout(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $beneficiary = Beneficiary::factory()->named('DETAIL', 'M', 'RECORD')->create([
            'contact_no' => '0917 123 4567',
            'skills_training_needed' => 'AGRICULTURE CROPS PRODUCTION',
        ]);

        $this->actingAs($admin)->get(route('beneficiaries.show', $beneficiary))
            ->assertOk()
            ->assertSee('Identity')
            ->assertSee('Address')
            ->assertSee('Identification &amp; contact', false)
            ->assertSee('Employment &amp; assistance', false)
            ->assertSee('Additional information')
            ->assertSee('Duplicate review')
            ->assertSee('History')
            ->assertSee('Age (computed)')
            ->assertSee('Age status')
            ->assertSee('Beneficiary ID')
            ->assertSee('Government ID')
            ->assertSee('Contact no.')
            ->assertSee('Skills / training needed')
            ->assertSee('AGRICULTURE CROPS PRODUCTION')
            ->assertSee('Edit')
            ->assertSee('id="deleteBeneficiaryModal"', false);
    }
}
