<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdCardPrintTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function beneficiary(array $attributes = []): Beneficiary
    {
        return Beneficiary::factory()
            ->named('ADA', 'ADAM', 'TESTER')
            ->withBirthdate('1990-05-05')
            ->create(array_merge([
                'barangay' => 'MATILAC',
                'city_municipality' => 'PAHAMUDDIN',
                'province' => 'SGA-BARMM',
                'district' => 'LONE',
                'dependent_name' => 'SECRET DEPENDENT',
            ], $attributes));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $beneficiary = $this->beneficiary();

        $this->get('/id-cards')->assertRedirect('/login');
        $this->get('/id-cards/print?ids[]='.$beneficiary->id)->assertRedirect('/login');
        $this->get('/beneficiaries/'.$beneficiary->id.'/card')->assertRedirect('/login');
    }

    public function test_single_card_shows_front_and_back_content(): void
    {
        $beneficiary = $this->beneficiary(['extension_name' => 'JR']);

        $response = $this->actingAs($this->staff())
            ->get('/beneficiaries/'.$beneficiary->id.'/card');

        $response->assertOk();

        // front
        $response->assertSee('ID NO.');
        $response->assertSee($beneficiary->beneficiary_code);
        $response->assertSee('ADA ADAM TESTER JR');
        $response->assertSee('Issued: '.$beneficiary->created_at->format('m/d/Y'));
        $response->assertSee('Expires: 3 months after implementation');
        $response->assertSee('images/id-cards/header-band.png');

        // back
        $response->assertSee('Birthdate:');
        $response->assertSee('1990-05-05');
        $response->assertSee('Address:');
        $response->assertSee('MATILAC, PAHAMUDDIN, SGA-BARMM, LONE');
        $response->assertSee('In case of emergency, please notify:');
        $response->assertSee('Contact no.:');
        $response->assertSee('REMINDERS');
        $response->assertSee('IMELDA GATINAO');
        $response->assertSee('FRANCIS S. TOLENTINO');

        // emergency contact is always blank on the printed card
        $response->assertDontSee('SECRET DEPENDENT');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'id_card.printed',
            'entity_type' => 'beneficiary',
            'entity_id' => $beneficiary->id,
        ]);
    }

    public function test_back_address_omits_blank_parts(): void
    {
        $beneficiary = $this->beneficiary([
            'city_municipality' => null,
            'district' => null,
            'province' => 'SGA-BARMM',
        ]);

        $this->actingAs($this->staff())
            ->get('/beneficiaries/'.$beneficiary->id.'/card')
            ->assertOk()
            ->assertSee('MATILAC, SGA-BARMM');
    }

    public function test_batch_print_pairs_front_and_back_per_beneficiary(): void
    {
        $first = $this->beneficiary();
        $second = Beneficiary::factory()
            ->named('BRAVO', 'BEN', 'CARD')
            ->withBirthdate('1985-01-02')
            ->create(['barangay' => 'MATILAC']);

        $response = $this->actingAs($this->admin())
            ->get('/id-cards/print?ids[]='.$first->id.'&ids[]='.$second->id);

        $response->assertOk();
        $response->assertSee($first->beneficiary_code);
        $response->assertSee($second->beneficiary_code);

        $html = $response->getContent();
        $this->assertSame(2, substr_count($html, 'aria-label="ID card front"'));
        $this->assertSame(2, substr_count($html, 'aria-label="ID card back"'));
        $this->assertSame(1, substr_count($html, 'card-page no-break'), 'only the final back page skips the break');

        $this->assertSame(1, AuditLog::query()->where('action', 'id_card.printed')->count());
        $log = AuditLog::query()->where('action', 'id_card.printed')->firstOrFail();
        $this->assertSame(2, $log->metadata['count']);
        $this->assertNull($log->entity_id);
    }

    public function test_single_card_log_is_per_beneficiary(): void
    {
        $beneficiary = $this->beneficiary();

        $this->actingAs($this->staff())->get('/beneficiaries/'.$beneficiary->id.'/card');

        $log = AuditLog::query()->where('action', 'id_card.printed')->firstOrFail();
        $this->assertSame('single', $log->metadata['mode']);
        $this->assertSame($beneficiary->id, $log->entity_id);
    }

    public function test_batch_print_requires_a_selection(): void
    {
        $this->actingAs($this->staff())
            ->get('/id-cards/print')
            ->assertRedirect(route('id-cards.index'))
            ->assertSessionHas('error');
    }

    public function test_batch_print_is_capped(): void
    {
        $ids = implode('&', array_map(fn ($id) => 'ids[]='.$id, range(1, 501)));

        $this->actingAs($this->staff())
            ->get('/id-cards/print?'.$ids)
            ->assertRedirect(route('id-cards.index'))
            ->assertSessionHas('error');
    }

    public function test_archived_beneficiary_card_is_still_printable(): void
    {
        $beneficiary = $this->beneficiary(['archived_at' => now()]);

        $this->actingAs($this->staff())
            ->get('/beneficiaries/'.$beneficiary->id.'/card')
            ->assertOk()
            ->assertSee($beneficiary->beneficiary_code);
    }

    public function test_selection_page_lists_beneficiaries_with_checkboxes(): void
    {
        $beneficiary = $this->beneficiary();

        $this->actingAs($this->staff())
            ->get('/id-cards')
            ->assertOk()
            ->assertSee($beneficiary->full_name)
            ->assertSee('name="ids[]"', false)
            ->assertSee('Print selected cards');
    }
}
