<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\OfflineServer;
use App\Models\OfflineServerEncoder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    private function encoder(): User
    {
        return User::factory()->create(['role' => User::ROLE_ENCODER]);
    }

    private function manager(): OfflineServerEncoder
    {
        $server = OfflineServer::factory()->create();

        return OfflineServerEncoder::query()->create([
            'offline_server_id' => $server->id,
            'encoder_uuid' => (string) Str::uuid(),
            'username' => 'manager_'.Str::lower(Str::random(6)),
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
            'reported_at' => now(),
        ]);
    }

    private function managerUser(OfflineServerEncoder $manager): User
    {
        return User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'uuid' => $manager->encoder_uuid,
        ]);
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
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
                'first_name' => 'JUAN',
                'middle_name' => 'DELA',
                'last_name' => 'CRUZ',
                'birthdate' => '1990-01-15',
            ]))
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
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
                'first_name' => 'FORGED',
                'last_name' => 'BATCH',
                'beneficiary_batch_id' => $other->id,
            ]))
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
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
                'first_name' => 'LATE',
                'last_name' => 'ARRIVAL',
            ]))
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

    public function test_the_delete_panel_warns_that_the_members_are_deleted_too(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->named('Cascade Batch')->create();

        Beneficiary::factory()->count(2)->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('also permanently deletes')
            ->assertSee('id="deleteBatchModal"', false)
            ->assertDontSee('confirm_name', false)
            ->assertDontSee('Reason (optional)')
            ->assertSee(route('beneficiary-batches.destroy', $batch), false);
    }

    public function test_the_old_manual_beneficiary_picker_stays_gone(): void
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
    }

    public function test_admin_can_assign_unassigned_beneficiaries_to_a_batch(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create();
        $first = Beneficiary::factory()->create(['first_name' => 'JOMAR', 'last_name' => 'ABAS']);
        $second = Beneficiary::factory()->create(['first_name' => 'NIDA', 'last_name' => 'SINSUAT']);

        $this->actingAs($admin)
            ->post('/batches/'.$batch->id.'/members', ['ids' => [$first->id, $second->id]])
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHasNoErrors();

        $this->assertSame($batch->id, $first->refresh()->beneficiary_batch_id);
        $this->assertSame($batch->id, $second->refresh()->beneficiary_batch_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary_batch.assigned',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => $batch->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_assigning_skips_beneficiaries_still_assigned_to_another_batch(): void
    {
        $admin = $this->admin();
        $source = BeneficiaryBatch::factory()->named('Source Batch')->create();
        $target = BeneficiaryBatch::factory()->named('Target Batch')->create();

        $taken = Beneficiary::factory()->create(['beneficiary_batch_id' => $source->id]);
        $free = Beneficiary::factory()->create();

        $this->actingAs($admin)
            ->post('/batches/'.$target->id.'/members', ['ids' => [$taken->id, $free->id]])
            ->assertRedirect(route('beneficiary-batches.show', $target))
            ->assertSessionHasNoErrors();

        // The person still filed in the source batch is not poached; only the
        // genuinely unassigned record moves.
        $this->assertSame($source->id, $taken->refresh()->beneficiary_batch_id);
        $this->assertSame($target->id, $free->refresh()->beneficiary_batch_id);
    }

    public function test_assignment_requires_an_open_batch(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->closed()->create();
        $beneficiary = Beneficiary::factory()->create();

        $this->actingAs($admin)
            ->post('/batches/'.$batch->id.'/members', ['ids' => [$beneficiary->id]])
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHas('error');

        $this->assertNull($beneficiary->refresh()->beneficiary_batch_id);
    }

    public function test_an_encoder_cannot_assign_beneficiaries_to_a_batch(): void
    {
        $encoder = $this->encoder();
        $batch = BeneficiaryBatch::factory()->create();
        $beneficiary = Beneficiary::factory()->create();

        $this->actingAs($encoder)
            ->post('/batches/'.$batch->id.'/members', ['ids' => [$beneficiary->id]])
            ->assertForbidden();

        $this->assertNull($beneficiary->refresh()->beneficiary_batch_id);
    }

    public function test_the_batch_page_offers_assignment_of_unassigned_beneficiaries(): void
    {
        $admin = $this->admin();
        $other = BeneficiaryBatch::factory()->named('Other Batch')->create();
        $batch = BeneficiaryBatch::factory()->named('Open Batch')->create();

        $free = Beneficiary::factory()->create(['first_name' => 'FREE', 'last_name' => 'FLOATER']);
        $taken = Beneficiary::factory()->create(['beneficiary_batch_id' => $other->id]);

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('Assign existing beneficiaries')
            ->assertSee(route('beneficiary-batches.members.store', $batch), false)
            ->assertSee($free->beneficiary_code)
            ->assertDontSee($taken->beneficiary_code);
    }

    public function test_the_assignment_panel_is_hidden_when_the_batch_is_closed(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->closed()->create();

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertDontSee('Assign existing beneficiaries');
    }

    public function test_registering_a_beneficiary_with_an_existing_government_id_returns_a_validation_error(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create();
        $location = $this->seededLocation();

        Beneficiary::factory()->create(['source_id_no' => 'TEST-GOV-ID-DUP']);

        $this->actingAs($admin)
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
                'source_id_no' => 'TEST-GOV-ID-DUP',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['source_id_no']);

        // Exactly one record exists for that government ID: no second
        // registration was created, and the server did not error out.
        $this->assertSame(1, Beneficiary::query()->where('source_id_no', 'TEST-GOV-ID-DUP')->count());
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

    public function test_deleting_a_batch_also_deletes_its_beneficiaries(): void
    {
        $admin = $this->admin();

        $batch = BeneficiaryBatch::factory()->named('Populated Batch')->create();

        $members = Beneficiary::factory()->count(3)->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($admin)
            ->delete('/batches/'.$batch->id, [
                'confirm_name' => 'Populated Batch',
                'delete_reason' => 'Wrong batch',
            ])
            ->assertRedirect(route('beneficiary-batches.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('beneficiary_batches', ['id' => $batch->id]);

        foreach ($members as $member) {
            $this->assertDatabaseMissing('beneficiaries', ['id' => $member->id]);

            $this->assertDatabaseHas('audit_logs', [
                'action' => 'beneficiary.deleted',
                'entity_type' => 'beneficiary',
                'entity_id' => $member->id,
            ]);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary_batch.deleted',
            'entity_id' => $batch->id,
        ]);
    }

    public function test_deleting_a_batch_leaves_beneficiaries_of_other_batches_untouched(): void
    {
        $admin = $this->admin();

        $batch = BeneficiaryBatch::factory()->named('Deleted Batch')->create();
        $other = BeneficiaryBatch::factory()->named('Kept Batch')->create();

        $member = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);
        $kept = Beneficiary::factory()->create(['beneficiary_batch_id' => $other->id]);
        $unassigned = Beneficiary::factory()->create();

        $this->actingAs($admin)
            ->delete('/batches/'.$batch->id, ['confirm_name' => 'Deleted Batch'])
            ->assertRedirect(route('beneficiary-batches.index'));

        $this->assertDatabaseMissing('beneficiaries', ['id' => $member->id]);
        $this->assertDatabaseHas('beneficiaries', ['id' => $kept->id]);
        $this->assertDatabaseHas('beneficiaries', ['id' => $unassigned->id]);
    }

    public function test_deleting_a_batch_requires_the_typed_batch_name_to_match(): void
    {
        $admin = $this->admin();

        $batch = BeneficiaryBatch::factory()->named('Protected Batch')->create();
        $member = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($admin)
            ->delete('/batches/'.$batch->id, ['confirm_name' => 'WRONG NAME'])
            ->assertSessionHasErrors('confirm_name');

        $this->assertDatabaseHas('beneficiary_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('beneficiaries', ['id' => $member->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'beneficiary_batch.deleted']);
    }

    public function test_an_empty_batch_can_be_deleted(): void
    {
        $admin = $this->admin();

        $empty = BeneficiaryBatch::factory()->named('Empty Batch')->create();

        $this->actingAs($admin)
            ->delete('/batches/'.$empty->id, ['confirm_name' => 'Empty Batch'])
            ->assertRedirect(route('beneficiary-batches.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('beneficiary_batches', ['id' => $empty->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary_batch.deleted',
            'entity_id' => $empty->id,
        ]);
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

    public function test_admin_can_assign_a_manager_when_creating_a_batch(): void
    {
        $admin = $this->admin();
        $manager = $this->manager();

        $this->actingAs($admin)
            ->post('/batches', [
                'name' => 'Managed Batch',
                'manager_id' => $manager->id,
            ])
            ->assertSessionHasNoErrors();

        $batch = BeneficiaryBatch::query()->firstOrFail();

        $this->assertSame($manager->id, $batch->manager_id);
        $this->assertSame($manager->encoder_uuid, $batch->manager_uuid);
    }

    public function test_admin_can_assign_and_unassign_a_manager_on_an_existing_batch(): void
    {
        $admin = $this->admin();
        $manager = $this->manager();
        $batch = BeneficiaryBatch::factory()->named('Reassignable Batch')->create();

        $this->actingAs($admin)
            ->put('/batches/'.$batch->id, [
                'name' => $batch->name,
                'status' => $batch->status,
                'manager_id' => $manager->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($manager->id, $batch->refresh()->manager_id);
        $this->assertSame($manager->encoder_uuid, $batch->manager_uuid);

        $this->actingAs($admin)
            ->put('/batches/'.$batch->id, [
                'name' => $batch->name,
                'status' => $batch->status,
                'manager_id' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($batch->refresh()->manager_id);
        $this->assertNull($batch->manager_uuid);
    }

    public function test_only_active_managers_can_be_assigned_to_a_batch(): void
    {
        $admin = $this->admin();
        $server = OfflineServer::factory()->create();
        $encoder = OfflineServerEncoder::query()->create([
            'offline_server_id' => $server->id,
            'encoder_uuid' => (string) Str::uuid(),
            'username' => 'an_encoder',
            'role' => User::ROLE_ENCODER,
            'is_active' => true,
            'reported_at' => now(),
        ]);
        $inactiveManager = OfflineServerEncoder::query()->create([
            'offline_server_id' => $server->id,
            'encoder_uuid' => (string) Str::uuid(),
            'username' => 'inactive_manager',
            'role' => User::ROLE_MANAGER,
            'is_active' => false,
            'reported_at' => now(),
        ]);
        $batch = BeneficiaryBatch::factory()->create();

        $this->actingAs($admin)
            ->put('/batches/'.$batch->id, [
                'name' => $batch->name,
                'status' => $batch->status,
                'manager_id' => $encoder->id,
            ])
            ->assertSessionHasErrors(['manager_id']);

        $this->actingAs($admin)
            ->put('/batches/'.$batch->id, [
                'name' => $batch->name,
                'status' => $batch->status,
                'manager_id' => $inactiveManager->id,
            ])
            ->assertSessionHasErrors(['manager_id']);

        $this->assertNull($batch->refresh()->manager_id);
    }

    public function test_an_assigned_manager_can_update_the_batch_and_remove_members(): void
    {
        $manager = $this->manager();
        $managerUser = $this->managerUser($manager);
        $batch = BeneficiaryBatch::factory()->named('Manager Batch')->create([
            'manager_id' => $manager->id,
        ]);
        $beneficiary = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($managerUser)
            ->put('/batches/'.$batch->id, [
                'name' => 'Manager Renamed Batch',
                'status' => 'closed',
            ])
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHasNoErrors();

        $batch->refresh();

        $this->assertSame('Manager Renamed Batch', $batch->name);
        $this->assertSame('closed', $batch->status);

        $this->actingAs($managerUser)
            ->delete('/batches/'.$batch->id.'/members', ['ids' => [$beneficiary->id]])
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHasNoErrors();

        $this->assertNull($beneficiary->refresh()->beneficiary_batch_id);
    }

    public function test_a_manager_assigned_by_synced_uuid_can_manage_the_batch(): void
    {
        $manager = $this->managerUser($this->manager());
        $batch = BeneficiaryBatch::factory()->create(['manager_uuid' => $manager->uuid]);

        $this->actingAs($manager)
            ->put('/batches/'.$batch->id, [
                'name' => 'Synced Manager Batch',
                'status' => 'closed',
            ])
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHasNoErrors();

        $this->assertSame('Synced Manager Batch', $batch->refresh()->name);
        $this->assertSame('closed', $batch->status);
    }

    public function test_a_manager_cannot_manage_a_batch_they_are_not_assigned_to(): void
    {
        $manager = $this->managerUser($this->manager());
        $otherManager = $this->manager();
        $batch = BeneficiaryBatch::factory()->create(['manager_id' => $otherManager->id]);
        $beneficiary = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($manager)
            ->put('/batches/'.$batch->id, [
                'name' => 'Hijacked Batch',
                'status' => 'closed',
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->delete('/batches/'.$batch->id.'/members', ['ids' => [$beneficiary->id]])
            ->assertForbidden();

        $this->assertNotSame('Hijacked Batch', $batch->refresh()->name);
        $this->assertSame($batch->id, $beneficiary->refresh()->beneficiary_batch_id);
    }

    public function test_a_manager_cannot_reassign_their_own_batch(): void
    {
        $manager = $this->manager();
        $managerUser = $this->managerUser($manager);
        $otherManager = $this->manager();
        $batch = BeneficiaryBatch::factory()->create(['manager_id' => $manager->id]);

        $this->actingAs($managerUser)
            ->put('/batches/'.$batch->id, [
                'name' => $batch->name,
                'status' => $batch->status,
                'manager_id' => $otherManager->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($manager->id, $batch->refresh()->manager_id);
    }

    public function test_offline_installations_cannot_create_batches(): void
    {
        config(['sync.instance_mode' => 'offline']);

        $manager = $this->managerUser($this->manager());

        $this->actingAs($manager)
            ->post('/batches', ['name' => 'Offline Created Batch'])
            ->assertForbidden();

        $this->assertDatabaseMissing('beneficiary_batches', ['name' => 'Offline Created Batch']);

        $this->actingAs($manager)->get('/batches')
            ->assertOk()
            ->assertDontSee('Create Batch')
            ->assertDontSee('batch-create-form', false)
            ->assertSee('Batches are created on the central server');

        $this->actingAs($manager)->get('/beneficiaries')
            ->assertOk()
            ->assertDontSee('Create Batch')
            ->assertDontSee('batch-create-form', false);
    }

    public function test_the_central_server_still_allows_batch_creation(): void
    {
        config(['sync.instance_mode' => 'online']);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/batches', ['name' => 'Centrally Created Batch'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('beneficiary_batches', ['name' => 'Centrally Created Batch']);

        $this->actingAs($admin)->get('/batches')
            ->assertOk()
            ->assertSee('Create Batch');
    }

    public function test_a_manager_can_create_a_batch(): void
    {
        $manager = $this->managerUser($this->manager());

        $this->actingAs($manager)
            ->post('/batches', ['name' => 'Manager Created Batch'])
            ->assertSessionHasNoErrors();

        $this->assertSame($manager->id, BeneficiaryBatch::query()->firstOrFail()->created_by);

        $this->actingAs($manager)->get('/batches')
            ->assertOk()
            ->assertSee('Create Batch');
    }

    public function test_batch_views_display_the_assigned_manager(): void
    {
        $admin = $this->admin();
        $manager = $this->manager();
        $manager->update(['username' => 'area_manager']);
        $batch = BeneficiaryBatch::factory()->named('Visible Manager Batch')->create([
            'manager_id' => $manager->id,
        ]);

        $this->actingAs($admin)->get('/batches')
            ->assertOk()
            ->assertSee('area_manager');

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('area_manager');
    }

    public function test_the_manager_picker_is_only_available_to_admins(): void
    {
        $admin = $this->admin();
        $manager = $this->manager();
        $managerUser = $this->managerUser($manager);
        $batch = BeneficiaryBatch::factory()->create(['manager_id' => $manager->id]);

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('batch-edit-manager', false);

        $this->actingAs($managerUser)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('Settings')
            ->assertDontSee('batch-edit-manager', false);
    }

    public function test_an_encoder_of_the_assigned_manager_can_add_a_beneficiary(): void
    {
        $manager = $this->manager();
        $this->managerUser($manager);
        $encoder = $this->encoder();
        $batch = BeneficiaryBatch::factory()->named('Encoder Batch')->create([
            'manager_id' => $manager->id,
            'manager_uuid' => $manager->encoder_uuid,
        ]);
        $location = $this->seededLocation();

        $this->actingAs($encoder)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('Add Beneficiary');

        $this->actingAs($encoder)
            ->get('/batches/'.$batch->id.'/beneficiaries/create')
            ->assertOk();

        $this->actingAs($encoder)
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
                'first_name' => 'ENCODER',
                'last_name' => 'ADDED',
            ]))
            ->assertRedirect(route('beneficiary-batches.show', $batch))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('beneficiaries', [
            'first_name' => 'ENCODER',
            'last_name' => 'ADDED',
            'beneficiary_batch_id' => $batch->id,
        ]);
    }

    public function test_an_encoder_cannot_add_to_a_batch_assigned_to_another_manager(): void
    {
        $otherManager = $this->manager();
        $encoder = $this->encoder();
        $batch = BeneficiaryBatch::factory()->create([
            'manager_id' => $otherManager->id,
            'manager_uuid' => $otherManager->encoder_uuid,
        ]);
        $location = $this->seededLocation();

        $this->actingAs($encoder)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertDontSee('Add Beneficiary');

        $this->actingAs($encoder)
            ->get('/batches/'.$batch->id.'/beneficiaries/create')
            ->assertForbidden();

        $this->actingAs($encoder)
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location, [
                'first_name' => 'BLOCKED',
                'last_name' => 'ENCODER',
            ]))
            ->assertForbidden();

        $this->assertDatabaseMissing('beneficiaries', [
            'first_name' => 'BLOCKED',
            'last_name' => 'ENCODER',
        ]);
    }

    public function test_management_actions_are_hidden_from_unassigned_managers_and_staff(): void
    {
        $staff = $this->staff();
        $unassignedManager = $this->managerUser($this->manager());
        $batch = BeneficiaryBatch::factory()->create();

        $this->actingAs($staff)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertDontSee('Add Beneficiary')
            ->assertDontSee('Settings');

        $this->actingAs($unassignedManager)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertDontSee('Add Beneficiary')
            ->assertDontSee('Settings');

        $this->actingAs($staff)->get('/batches')
            ->assertOk()
            ->assertDontSee('Create Batch');

        $this->actingAs($unassignedManager)->get('/batches')
            ->assertOk()
            ->assertSee('Create Batch');
    }

    public function test_a_manager_reported_by_an_offline_installation_is_available_for_assignment(): void
    {
        $admin = $this->admin();
        $manager = $this->manager();
        $manager->update(['username' => 'new_area_manager']);
        $batch = BeneficiaryBatch::factory()->create();

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('new_area_manager');
    }

    public function test_the_users_page_has_no_add_user_action(): void
    {
        $this->actingAs($this->admin())->get('/users')
            ->assertOk()
            ->assertDontSee('Add user');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post('/batches', ['name' => 'Guest Batch'])->assertRedirect('/login');
    }

    public function test_the_batch_page_uses_the_shared_management_workspace_layout(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->named('Workspace Batch')->create(['capacity' => 10]);
        Beneficiary::factory()->count(2)->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('Workspace Batch')
            ->assertSee('Total members')
            ->assertSee('Beneficiary limit')
            ->assertSee('Available slots')
            ->assertSee('Batch status')
            ->assertSee('Batch capacity')
            ->assertSee('Batch Members')
            ->assertSee('Assign existing beneficiaries')
            ->assertSee('role="progressbar"', false)
            ->assertSee('only clears batch membership', false)
            ->assertSee('Add Beneficiary')
            ->assertSee(route('beneficiary-batches.beneficiaries.create', $batch), false);
    }

    public function test_an_empty_batch_shows_a_friendly_empty_state_with_actions(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->named('Empty Batch')->create();

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('No beneficiaries have been assigned to this batch yet.')
            ->assertSee('Add Beneficiary');
    }

    public function test_a_full_batch_shows_the_capacity_ceiling_and_blocks_add(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['capacity' => 1]);
        Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($admin)->get('/batches/'.$batch->id)
            ->assertOk()
            ->assertSee('100% full')
            ->assertSee('Batch full')
            ->assertSee('Limit reached')
            ->assertDontSee(route('beneficiary-batches.beneficiaries.create', $batch), false);
    }

    public function test_the_batches_page_uses_the_shared_management_workspace_layout(): void
    {
        $admin = $this->admin();
        $open = BeneficiaryBatch::factory()->named('Open Workspace Batch')->create(['capacity' => 10]);
        $closed = BeneficiaryBatch::factory()->named('Closed Workspace Batch')->closed()->create();
        Beneficiary::factory()->create(['beneficiary_batch_id' => $open->id]);

        $this->actingAs($admin)->get(route('beneficiary-batches.index'))
            ->assertOk()
            ->assertSee('Total batches')
            ->assertSee('Open')
            ->assertSee('Closed')
            ->assertSee('Filed beneficiaries')
            ->assertSee('All batches')
            ->assertSee('Open Workspace Batch')
            ->assertSee('Closed Workspace Batch')
            ->assertSee('Manage')
            ->assertSee('1 / 10')
            ->assertSee('Create Batch');
    }

    public function test_an_empty_batches_page_offers_the_create_action(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('beneficiary-batches.index'))
            ->assertOk()
            ->assertSee('No batches yet.')
            ->assertSee('Create Batch')
            ->assertSee('id="batchModal"', false);
    }

    public function test_a_batch_can_be_deleted_with_modal_confirmation_without_typing_or_a_reason(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->named('Modal Delete Batch')->create();
        $member = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($admin)
            ->delete('/batches/'.$batch->id)
            ->assertRedirect(route('beneficiary-batches.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('beneficiary_batches', ['id' => $batch->id]);
        $this->assertDatabaseMissing('beneficiaries', ['id' => $member->id]);
    }
}
