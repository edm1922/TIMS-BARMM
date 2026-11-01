<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use App\Services\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_name_and_birthdate_is_flagged_as_candidate(): void
    {
        $first = Beneficiary::factory()->named('SAME', 'NAME', 'HERE')->withBirthdate('1990-01-01')->create();

        $second = Beneficiary::factory()->named('SAME', 'NAME', 'HERE')->withBirthdate('1990-01-01')->create();

        $this->assertNotSame($first->beneficiary_code, $second->beneficiary_code);
        $this->assertSame('candidate', $second->duplicate_status);
        $this->assertStringEndsWith('-00002', $second->beneficiary_code);
    }

    public function test_a_duplicate_government_id_throws_a_clear_error_not_a_server_error(): void
    {
        Beneficiary::factory()->create(['source_id_no' => 'DUP-GOV-777']);

        $attributes = Beneficiary::factory()->make(['source_id_no' => 'DUP-GOV-777'])->getAttributes();

        try {
            Beneficiary::createWithCode($attributes);
            $this->fail('A duplicate government ID must not create a second record.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('already registered', $exception->getMessage());
        }
    }

    public function test_same_name_different_birthdate_is_weak_candidate_only(): void
    {
        Beneficiary::factory()->named('TWIN', null, 'NAMES')->withBirthdate('1990-01-01')->create();

        $other = Beneficiary::factory()->named('TWIN', null, 'NAMES')->withBirthdate('1975-06-30')->create();

        $detector = new DuplicateDetector;
        $result = $detector->findFor($other, $other->id);

        $this->assertNotNull($result);
        $this->assertSame('name_only_weak', $result['rule']);
        $this->assertSame('Same name only (weak match)', DuplicateDetector::ruleLabel($result['rule']));
    }

    public function test_different_people_are_not_flagged(): void
    {
        Beneficiary::factory()->named('ALPHA', null, 'ONE')->withBirthdate('1990-01-01')->create();

        $other = Beneficiary::factory()->named('BETA', null, 'TWO')->withBirthdate('1991-02-02')->create();

        $this->assertSame('none', $other->duplicate_status);

        $detector = new DuplicateDetector;
        $this->assertNull($detector->findFor($other, $other->id));
    }

    public function test_case_and_whitespace_do_not_hide_duplicates(): void
    {
        Beneficiary::factory()->named('  mixed ', ' case ', ' name ')->withBirthdate('1990-01-01')->create();

        $other = Beneficiary::factory()->named('MIXED', 'CASE', 'NAME')->withBirthdate('1990-01-01')->create();

        $detector = new DuplicateDetector;
        $result = $detector->findFor($other, $other->id);

        $this->assertNotNull($result);
        $this->assertSame('name_birthdate', $result['rule']);
    }

    public function test_same_source_government_id_is_candidate(): void
    {
        // Use different origin_server_uuid values so the database unique constraint
        // (source_id_no, origin_server_uuid) does not block the inserts, while
        // the application-level duplicate detector still flags the match.
        $beneficiary = Beneficiary::factory()->named('ID', null, 'OWNER')->create(['source_id_no' => '123456789', 'origin_server_uuid' => 'ORIGIN-A']);

        $other = Beneficiary::factory()->named('OTHER', null, 'PERSON')->create(['source_id_no' => '123456789', 'origin_server_uuid' => 'ORIGIN-B']);

        $detector = new DuplicateDetector;
        $result = $detector->findFor($other, $other->id);

        $this->assertNotNull($result);
        $this->assertSame('source_id_no', $result['rule']);
    }

    public function test_confirmed_duplicate_status_survives_further_edits(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $location = $this->seededLocation();

        $beneficiary = Beneficiary::factory()->named('KEEP', null, 'FLAG')->create();

        $this->actingAs($admin)->post('/beneficiaries/'.$beneficiary->id.'/duplicate-resolve', [
            'decision' => 'confirmed_duplicate',
            'note' => 'reviewed with field office',
        ]);

        $this->actingAs($admin)->put('/beneficiaries/'.$beneficiary->id, $this->beneficiaryPayload($location, [
            'first_name' => 'KEEP',
            'middle_name' => null,
            'last_name' => 'FLAG',
            'birthdate' => '1990-03-03',
            'sex' => 'MALE',
            'civil_status' => 'SINGLE',
        ]));

        $this->assertSame('confirmed_duplicate', $beneficiary->refresh()->duplicate_status);
    }

    public function test_cleared_flag_is_re_raised_when_still_matching_on_edit(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $location = $this->seededLocation();

        $original = Beneficiary::factory()->named('RE', null, 'RAISE')->withBirthdate('1990-01-01')->create();
        $beneficiary = Beneficiary::factory()->named('RE', null, 'RAISE')->withBirthdate('1990-01-01')->create();

        $this->actingAs($admin)->post('/beneficiaries/'.$beneficiary->id.'/duplicate-resolve', [
            'decision' => 'cleared',
            'note' => 'different people',
        ]);

        $this->assertSame('cleared', $beneficiary->refresh()->duplicate_status);

        $this->actingAs($admin)->put('/beneficiaries/'.$beneficiary->id, $this->beneficiaryPayload($location, [
            'first_name' => 'RE',
            'middle_name' => null,
            'last_name' => 'RAISE',
            'birthdate' => '1990-01-01',
            'sex' => 'FEMALE',
            'civil_status' => 'SINGLE',
        ]));

        $this->assertSame('candidate', $beneficiary->refresh()->duplicate_status);
        $this->assertNotNull($original->id);
    }

    public function test_detector_ignores_itself_but_not_others(): void
    {
        $beneficiary = Beneficiary::factory()->named('ALONE', null, 'PERSON')->withBirthdate('1990-01-01')->create();

        $detector = new DuplicateDetector;

        $this->assertNull($detector->findFor($beneficiary, $beneficiary->id));
        $this->assertNotNull($detector->findFor($beneficiary, null));
    }

    public function test_a_candidate_records_the_reason_it_was_flagged(): void
    {
        Beneficiary::factory()->named('REASON', null, 'STORED')->withBirthdate('1990-01-01')->create();

        $candidate = Beneficiary::factory()->named('REASON', null, 'STORED')->withBirthdate('1990-01-01')->create();

        $this->assertSame('candidate', $candidate->duplicate_status);
        $this->assertSame('name_birthdate', $candidate->duplicate_rule);
    }

    public function test_the_list_shows_the_reason_instead_of_a_generic_candidate_label(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Beneficiary::factory()->named('SHOWN', null, 'REASON')->withBirthdate('1990-01-01')->create();

        Beneficiary::factory()->named('SHOWN', null, 'REASON')->withBirthdate('1990-01-01')->create();

        // The list no longer carries a duplicate column; the profile shows it.
        $this->actingAs($admin)->get('/beneficiaries')
            ->assertOk()
            ->assertDontSee('Same name and birthdate');

        $candidate = Beneficiary::query()->where('first_name', 'SHOWN')->latest('id')->firstOrFail();

        $this->actingAs($admin)->get('/beneficiaries/'.$candidate->id)
            ->assertOk()
            ->assertSee('Same name and birthdate')
            ->assertDontSee('Duplicate candidate');
    }

    public function test_a_cleared_flag_is_shown_as_cleared_duplication(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $beneficiary = Beneficiary::factory()->named('CLEARED', null, 'FLAG')->create();

        $this->actingAs($admin)->post('/beneficiaries/'.$beneficiary->id.'/duplicate-resolve', [
            'decision' => 'cleared',
            'note' => 'different people',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/beneficiaries/'.$beneficiary->id)
            ->assertOk()
            ->assertSee('Cleared duplication')
            ->assertSee('text-bg-success', false);
    }

    public function test_a_confirmed_duplicate_keeps_the_red_confirmed_label(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $beneficiary = Beneficiary::factory()->named('CONFIRM', null, 'FLAG')->create();

        $this->actingAs($admin)->post('/beneficiaries/'.$beneficiary->id.'/duplicate-resolve', [
            'decision' => 'confirmed_duplicate',
            'note' => 'same person',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/beneficiaries/'.$beneficiary->id)
            ->assertOk()
            ->assertSee('Confirmed duplicate')
            ->assertDontSee('Cleared duplication');
    }
}
