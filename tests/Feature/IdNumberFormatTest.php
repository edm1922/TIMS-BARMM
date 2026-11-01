<?php

namespace Tests\Feature;

use App\Models\BeneficiaryBatch;
use App\Models\User;
use App\Support\BeneficiaryOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Government ID No. formatting: known ID types store their number in the
 * official format (separators applied automatically), PHILHEALTH is a
 * selectable ID type, and unknown/free-form types are never mangled.
 */
class IdNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_philhealth_is_a_selectable_id_type(): void
    {
        $this->assertArrayHasKey('PHILHEALTH', BeneficiaryOptions::idTypes());
    }

    public function test_known_id_types_are_formatted(): void
    {
        $cases = [
            ['NATIONAL ID', '5981094682764924', '5981-0946-8276-4924'],
            ['NATIONAL ID', '417043023942', '4170-4302-3942'],
            ['PHILSYS ID', '5981094682764924', '5981-0946-8276-4924'],
            ['PHILHEALTH', '123456789012', '12-345678901-2'],
            ['TIN ID', '123456789', '123-456-789'],
        ];

        foreach ($cases as [$idType, $input, $expected]) {
            $this->assertSame(
                $expected,
                BeneficiaryOptions::formatIdNumber($idType, $input),
                $idType,
            );
        }
    }

    public function test_voter_id_stays_free_form_because_card_layouts_vary(): void
    {
        // Real cards appear both dashed (4-5-13) and undashed — auto-masking
        // would mangle one or the other, so the value is kept as typed.
        $this->assertSame(
            '3831-0006A-F0368GKL20000',
            BeneficiaryOptions::formatIdNumber("VOTER'S ID", '3831-0006A-F0368GKL20000'),
        );
    }

    public function test_unknown_types_and_blank_values_pass_through(): void
    {
        $this->assertSame('ABC-123', BeneficiaryOptions::formatIdNumber('OTHERS', 'ABC-123'));
        $this->assertSame('whatever', BeneficiaryOptions::formatIdNumber('UMID', 'whatever'));
        $this->assertNull(BeneficiaryOptions::formatIdNumber('NATIONAL ID', null));
        $this->assertSame('', BeneficiaryOptions::formatIdNumber('NATIONAL ID', '---'));
    }

    public function test_registering_a_beneficiary_stores_the_formatted_government_id(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $batch = BeneficiaryBatch::factory()->create();
        $location = $this->seededLocation();

        $this->actingAs($admin)
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
                'id_type' => 'PHILHEALTH',
                'source_id_no' => '12 345678901 2', // unformatted on purpose
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('beneficiaries', [
            'id_type' => 'PHILHEALTH',
            'source_id_no' => '12-345678901-2',
        ]);
    }
}
