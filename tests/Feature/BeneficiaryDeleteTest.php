<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BeneficiaryDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SyncSetting::flushCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_MANAGER]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    private function beneficiary(): Beneficiary
    {
        return Beneficiary::factory()->named('DELETE', 'Q', 'CASE')->create([
            'location_code' => '1900702001',
        ]);
    }

    public function test_a_guest_cannot_delete_a_beneficiary(): void
    {
        $beneficiary = $this->beneficiary();

        $this->delete(route('beneficiaries.destroy', $beneficiary), [
            'confirm_code' => $beneficiary->beneficiary_code,
        ])->assertRedirect('/login');

        $this->assertDatabaseHas('beneficiaries', ['id' => $beneficiary->id]);
    }

    public function test_staff_may_not_delete_a_beneficiary(): void
    {
        $beneficiary = $this->beneficiary();

        $this->actingAs($this->staff())
            ->delete(route('beneficiaries.destroy', $beneficiary), [
                'confirm_code' => $beneficiary->beneficiary_code,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('beneficiaries', ['id' => $beneficiary->id]);
    }

    public function test_a_manager_can_delete_a_beneficiary_and_it_is_audited(): void
    {
        $manager = $this->manager();
        $beneficiary = $this->beneficiary();

        $this->actingAs($manager)
            ->get(route('beneficiaries.show', $beneficiary))
            ->assertOk()
            ->assertSee('deleteBeneficiaryModal', false);

        $this->actingAs($manager)
            ->delete(route('beneficiaries.destroy', $beneficiary), [
                'confirm_code' => $beneficiary->beneficiary_code,
                'delete_reason' => 'Demo record',
            ])
            ->assertRedirect(route('beneficiaries.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('beneficiaries', ['id' => $beneficiary->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary.deleted',
            'entity_type' => 'beneficiary',
            'entity_id' => $beneficiary->id,
        ]);
    }

    public function test_an_admin_can_delete_a_beneficiary_and_it_is_audited(): void
    {
        $admin = $this->admin();
        $beneficiary = $this->beneficiary();

        $this->actingAs($admin)
            ->delete(route('beneficiaries.destroy', $beneficiary), [
                'confirm_code' => $beneficiary->beneficiary_code,
                'delete_reason' => 'Entered in error',
            ])
            ->assertRedirect(route('beneficiaries.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('beneficiaries', ['id' => $beneficiary->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'beneficiary.deleted',
            'entity_type' => 'beneficiary',
            'entity_id' => $beneficiary->id,
        ]);
    }

    public function test_deletion_requires_the_typed_beneficiary_id_to_match(): void
    {
        $admin = $this->admin();
        $beneficiary = $this->beneficiary();

        $this->actingAs($admin)
            ->delete(route('beneficiaries.destroy', $beneficiary), [
                'confirm_code' => 'WRONG-CODE',
            ])
            ->assertSessionHasErrors('confirm_code');

        $this->assertDatabaseHas('beneficiaries', ['id' => $beneficiary->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'beneficiary.deleted']);
    }

    public function test_a_beneficiary_with_an_open_sync_review_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $beneficiary = $this->beneficiary();

        SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => null,
            'entity_type' => 'beneficiary',
            'entity_uuid' => $beneficiary->global_uuid,
            'origin_server_uuid' => $beneficiary->origin_server_uuid,
            'local_payload' => ['first_name' => 'DELETE'],
            'remote_payload' => ['first_name' => 'DELETE'],
            'status' => 'open',
        ]);

        $this->actingAs($admin)
            ->delete(route('beneficiaries.destroy', $beneficiary), [
                'confirm_code' => $beneficiary->beneficiary_code,
            ])
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('beneficiaries', ['id' => $beneficiary->id]);
    }

    public function test_pending_uploads_are_superseded_by_the_deletion(): void
    {
        $admin = $this->admin();

        config([
            'sync.instance_mode' => 'offline',
            'sync.local_server_uuid' => '11111111-1111-4111-8111-111111111111',
        ]);
        SyncSetting::flushCache();

        $beneficiary = $this->beneficiary();

        $create = SyncOperation::query()->firstOrFail();
        $this->assertSame('created', $create->operation);
        $this->assertSame(SyncOperation::STATUS_PENDING, $create->status);

        $this->actingAs($admin)
            ->delete(route('beneficiaries.destroy', $beneficiary), [
                'confirm_code' => $beneficiary->beneficiary_code,
            ])
            ->assertSessionHas('success');

        // The superseded upload is retained with a reason, not discarded.
        $this->assertSame(SyncOperation::STATUS_SUPERSEDED, $create->fresh()->status);
        $this->assertNotNull($create->fresh()->last_error);

        // A tombstone is queued for the deletion itself.
        $this->assertDatabaseHas('sync_operations', [
            'entity_type' => 'beneficiary',
            'entity_uuid' => $beneficiary->global_uuid,
            'operation' => 'deleted',
            'status' => SyncOperation::STATUS_PENDING,
        ]);
    }

    public function test_a_beneficiary_can_be_deleted_with_modal_confirmation_without_typing_or_a_reason(): void
    {
        $admin = $this->admin();
        $beneficiary = $this->beneficiary();

        $this->actingAs($admin)
            ->delete(route('beneficiaries.destroy', $beneficiary))
            ->assertRedirect(route('beneficiaries.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('beneficiaries', ['id' => $beneficiary->id]);

        $log = AuditLog::query()
            ->where('action', 'beneficiary.deleted')
            ->where('entity_id', $beneficiary->id)
            ->firstOrFail();

        $this->assertNull(data_get($log->metadata, 'delete_reason'));
    }
}
