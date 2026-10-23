<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeneficiaryBatchTest extends TestCase
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

    public function test_admin_can_create_a_batch_and_is_sent_to_its_management_view(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from('/beneficiaries')
            ->post('/batches', [
                'name' => 'Batch 1 - SGA Pahamuddin',
                'description' => 'First release round',
            ]);

        $batch = BeneficiaryBatch::query()->firstOrFail();

        $response->assertRedirect(route('beneficiary-batches.show', $batch));
        $response->assertSessionHasNoErrors();

        $this->assertSame('Batch 1 - SGA Pahamuddin', $batch->name);
        $this->assertSame('First release round', $batch->description);
        $this->assertSame('open', $batch->status);
        $this->assertSame($admin->id, $batch->created_by);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary_batch.created',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => $batch->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_a_repeated_batch_creation_does_not_create_a_duplicate(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/batches', ['name' => 'Batch 1'])
            ->assertSessionHasNoErrors();

        $batch = BeneficiaryBatch::query()->firstOrFail();

        // A double submission (same name, different casing) opens the batch
        // that already exists instead of creating a second one.
        $this->actingAs($admin)
            ->post('/batches', ['name' => 'batch 1'])
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, BeneficiaryBatch::query()->count());
    }

    public function test_batch_name_is_required(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/batches', ['name' => ''])
            ->assertSessionHasErrors(['name']);

        $this->assertSame(0, BeneficiaryBatch::query()->count());
    }

    public function test_staff_cannot_create_or_change_batches(): void
    {
        $staff = $this->staff();
        $batch = BeneficiaryBatch::factory()->create();
        $beneficiary = Beneficiary::factory()->create();

        $this->actingAs($staff)
            ->post('/batches', ['name' => 'Staff Batch'])
            ->assertForbidden();

        $this->actingAs($staff)
            ->delete('/batches/'.$batch->id.'/members', ['ids' => [$beneficiary->id]])
            ->assertForbidden();

        $this->assertNull($beneficiary->refresh()->beneficiary_batch_id);
    }

    public function test_a_beneficiary_registered_in_a_batch_belongs_to_that_batch(): void
    {
        $staff = $this->staff();
        $location = $this->seededLocation();
        $batch = BeneficiaryBatch::factory()->named('Registration Batch')->create();

        $this->actingAs($staff)
            ->post('/batches/'.$batch->id.'/beneficiaries', [
                'first_name' => 'JUAN',
                'middle_name' => 'DELA',
                'last_name' => 'CRUZ',
                'birthdate' => '1990-01-15',
                'address' => $location->full_address,
                'location_code' => $location->psgc_code,
                'duplicate_check_done' => '1',
            ])
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHasNoErrors();

        $beneficiary = Beneficiary::query()->firstOrFail();

        $this->assertSame($batch->id, $beneficiary->beneficiary_batch_id);
        $this->assertSame(1, $batch->beneficiaries()->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary.created',
            'entity_type' => 'beneficiary',
            'entity_id' => $beneficiary->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary_batch.assigned',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => $batch->id,
        ]);
    }

    public function test_the_batch_of_a_registered_beneficiary_cannot_be_forged(): void
    {
        $staff = $this->staff();
        $location = $this->seededLocation();
        $batch = BeneficiaryBatch::factory()->named('Target Batch')->create();
        $other = BeneficiaryBatch::factory()->named('Other Batch')->create();

        $this->actingAs($staff)
            ->post('/batches/'.$batch->id.'/beneficiaries', [
                'first_name' => 'FORGED',
                'last_name' => 'BATCH',
                'address' => $location->full_address,
                'location_code' => $location->psgc_code,
                'beneficiary_batch_id' => $other->id,
                'duplicate_check_done' => '1',
            ])
            ->assertSessionHasNoErrors();

        // The batch comes from the route, never from the request body.
        $this->assertSame($batch->id, Beneficiary::query()->firstOrFail()->beneficiary_batch_id);
    }

    public function test_a_closed_batch_accepts_no_new_beneficiaries(): void
    {
        $staff = $this->staff();
        $location = $this->seededLocation();
        $batch = BeneficiaryBatch::factory()->named('Closed Batch')->closed()->create();

        $this->actingAs($staff)
            ->get('/batches/'.$batch->id.'/beneficiaries/create')
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHas('error');

        $this->actingAs($staff)
            ->post('/batches/'.$batch->id.'/beneficiaries', [
                'first_name' => 'LATE',
                'last_name' => 'ARRIVAL',
                'address' => $location->full_address,
                'location_code' => $location->psgc_code,
                'duplicate_check_done' => '1',
            ])
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHas('error');

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_admin_can_remove_a_beneficiary_from_a_batch_without_deleting_the_record(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create();

        $beneficiary = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($admin)
            ->delete('/batches/'.$batch->id.'/members', ['ids' => [$beneficiary->id]])
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHasNoErrors();

        $this->assertNull($beneficiary->refresh()->beneficiary_batch_id);
        $this->assertDatabaseHas('beneficiaries', ['id' => $beneficiary->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary_batch.unassigned',
            'user_id' => $admin->id,
        ]);
    }

    public function test_removing_from_a_batch_leaves_other_batches_untouched(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create();
        $other = BeneficiaryBatch::factory()->create();

        $mine = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);
        $theirs = Beneficiary::factory()->create(['beneficiary_batch_id' => $other->id]);

        $this->actingAs($admin)
            ->delete('/batches/'.$batch->id.'/members', ['ids' => [$mine->id, $theirs->id]])
            ->assertRedirect(route('beneficiary-batches.show', $batch));

        $this->assertNull($mine->refresh()->beneficiary_batch_id);
        $this->assertSame($other->id, $theirs->refresh()->beneficiary_batch_id);
    }

    public function test_batches_index_lists_the_batches(): void
    {
        $staff = $this->staff();

        BeneficiaryBatch::factory()->named('Listed Batch')->create();

        $this->actingAs($staff)->get('/batches')
            ->assertOk()
            ->assertSee('Listed Batch');
    }

    public function test_batch_management_view_lists_members_and_offers_add_beneficiary(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->named('Managed Batch')->create();
        $member = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('Managed Batch')
            ->assertSee('Add Beneficiary')
            ->assertSee($member->beneficiary_code)
            ->assertSee(route('beneficiary-batches.beneficiaries.create', $batch), false);
    }

    public function test_the_manual_beneficiary_picker_is_gone(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create();

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertDontSee('batch-member-form', false)
            ->assertDontSee('batch-member-search', false);

        $this->actingAs($admin)
            ->get('/batches/'.$batch->id.'/candidates')
            ->assertNotFound();

        $this->actingAs($admin)
            ->post('/batches/'.$batch->id.'/members', ['ids' => [1]])
            ->assertStatus(405);
    }

    public function test_beneficiaries_page_has_no_add_beneficiary_action(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/beneficiaries')
            ->assertOk()
            ->assertDontSee('Add Beneficiary')
            ->assertDontSee('Add beneficiary')
            ->assertDontSee('New beneficiary')
            ->assertDontSee('batch-assign-form')
            ->assertDontSee('batch-member-form');
    }

    public function test_beneficiaries_page_still_offers_the_batch_column_and_filter(): void
    {
        $admin = $this->admin();

        BeneficiaryBatch::factory()->named('Filtered Batch')->create();

        $this->actingAs($admin)->get('/beneficiaries')
            ->assertOk()
            ->assertSee('Filtered Batch')
            ->assertSee('Create Batch');
    }

    public function test_batch_can_only_be_deleted_when_empty(): void
    {
        $admin = $this->admin();

        $empty = BeneficiaryBatch::factory()->named('Empty Batch')->create();
        $populated = BeneficiaryBatch::factory()->named('Populated Batch')->create();

        $beneficiary = Beneficiary::factory()->create(['beneficiary_batch_id' => $populated->id]);

        $this->actingAs($admin)
            ->delete('/batches/'.$populated->id)
            ->assertRedirect(route('beneficiary-batches.show', $populated))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('beneficiary_batches', ['id' => $populated->id]);

        $this->actingAs($admin)
            ->delete('/batches/'.$empty->id)
            ->assertRedirect(route('beneficiary-batches.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('beneficiary_batches', ['id' => $empty->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary_batch.deleted',
            'entity_id' => $empty->id,
        ]);

        $this->assertSame($populated->id, $beneficiary->refresh()->beneficiary_batch_id);
    }

    public function test_admin_can_rename_and_close_a_batch(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->named('Old Name')->create();

        $this->actingAs($admin)
            ->put('/batches/'.$batch->id, [
                'name' => 'New Name',
                'description' => 'Updated',
                'status' => 'closed',
            ])
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHasNoErrors();

        $batch->refresh();

        $this->assertSame('New Name', $batch->name);
        $this->assertSame('Updated', $batch->description);
        $this->assertSame('closed', $batch->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary_batch.updated',
            'entity_id' => $batch->id,
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post('/batches', ['name' => 'Guest Batch'])->assertRedirect('/login');
    }
}
