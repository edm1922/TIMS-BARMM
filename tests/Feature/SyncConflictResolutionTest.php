<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\OfflineServer;
use App\Models\SyncConflict;
use App\Models\SyncEvent;
use App\Models\SyncRequest;
use App\Models\SyncRequestItem;
use App\Models\User;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncConflictResolutionTest extends TestCase
{
    use RefreshDatabase;

    private OfflineServer $server;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $issued = app(TokenService::class)->generateEnrollmentToken();
        $response = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Conflict Test Server',
            'server_uuid' => (string) Str::uuid(),
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'city_municipality',
            'scope_code' => '1900702000',
        ])->assertCreated();

        $data = $response->json('data');
        $this->server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();
        $this->token = $data['server_token'];
    }

    // -------------------------------------------------------------------------
    // Field-level conflict detection
    // -------------------------------------------------------------------------

    public function test_non_overlapping_changed_fields_are_auto_merged_without_conflict(): void
    {
        // Create a beneficiary on central at revision 1.
        $beneficiary = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'occupation' => 'Farmer',
            'revision' => 1,
        ]);

        // Central bumps revision to 2 by changing occupation (not notes).
        // The offline client does NOT know about this change yet.
        $beneficiary->forceFill(['revision' => 2, 'occupation' => 'Fisher'])->save();

        $opUuid = (string) Str::uuid();

        // Offline only changed notes — NOT occupation. The payload ONLY contains notes.
        // Since notes is not present in the remote payload (which only tracks occupation),
        // there is no overlap and the merge should succeed.
        $response = $this->withHeader((string) config('sync.server_token_header'), $this->token)
            ->postJson('/api/sync/v1/operations', [
                'operations' => [[
                    'operation_uuid' => $opUuid,
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $beneficiary->global_uuid,
                    'operation' => 'updated',
                    'base_revision' => 1, // stale — central is at revision 2
                    'changed_fields' => ['notes'],
                    'payload' => [
                        'notes' => 'Offline note update.', // Only notes changed, not occupation
                    ],
                ]],
            ]);

        $response->assertOk()->assertJsonPath('data.0.status', 'applied');

        // No conflict record created.
        $this->assertSame(0, SyncConflict::count());

        // The notes field was merged in.
        $this->assertSame('Offline note update.', $beneficiary->fresh()->notes);

        // occupation (central change) is preserved.
        $this->assertSame('Fisher', $beneficiary->fresh()->occupation);

        // An auto_merged event should be recorded.
        $this->assertSame(1, SyncEvent::where('type', 'operation.auto_merged')->count());
    }

    public function test_overlapping_changed_fields_create_a_conflict(): void
    {
        $beneficiary = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'notes' => 'Central note.',
            'revision' => 1,
        ]);

        // Central bumps the notes field too.
        $beneficiary->forceFill(['revision' => 2, 'notes' => 'Updated centrally.'])->save();

        $opUuid = (string) Str::uuid();

        $response = $this->withHeader((string) config('sync.server_token_header'), $this->token)
            ->postJson('/api/sync/v1/operations', [
                'operations' => [[
                    'operation_uuid' => $opUuid,
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $beneficiary->global_uuid,
                    'operation' => 'updated',
                    'base_revision' => 1,
                    'changed_fields' => ['notes'],
                    'payload' => [
                        'notes' => 'Offline competing note.',
                        'location_code' => '1900702001',
                    ],
                ]],
            ]);

        $response->assertOk()->assertJsonPath('data.0.status', 'conflict');
        $this->assertNotNull($response->json('data.0.conflict_uuid'));

        // A conflict record was created.
        $this->assertSame(1, SyncConflict::count());
        $conflict = SyncConflict::first();
        $this->assertSame('open', $conflict->status);

        // A conflict event was recorded.
        $this->assertSame(1, SyncEvent::where('type', 'operation.conflict')->count());

        // The 'diff' stores the overlapping fields.
        $this->assertContains('notes', $conflict->diff);

        // Central record is unchanged.
        $this->assertSame('Updated centrally.', $beneficiary->fresh()->notes);
    }

    // -------------------------------------------------------------------------
    // Online conflict-review UI (central / admin interface)
    // -------------------------------------------------------------------------

    public function test_resolving_a_conflict_from_the_conflict_workspace_settles_the_review_item(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $beneficiary = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'notes' => 'Old central note.',
        ]);
        $beneficiary->forceFill(['conflict_status' => 'open'])->save();

        $operationUuid = (string) Str::uuid();

        $conflict = SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => $beneficiary->global_uuid,
            'operation' => 'updated',
            'operation_uuid' => $operationUuid,
            'origin_server_uuid' => $this->server->server_uuid,
            'local_payload' => ['notes' => 'Offline version wins.'],
            'remote_payload' => ['notes' => 'Old central note.'],
            'diff' => ['notes'],
            'status' => 'open',
        ]);

        $request = SyncRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'status' => SyncRequest::STATUS_AWAITING_REVIEW,
            'total_operations' => 1,
            'submitted_at' => now(),
            'last_activity_at' => now(),
        ]);

        $item = SyncRequestItem::create([
            'sync_request_id' => $request->id,
            'operation_uuid' => $operationUuid,
            'entity_type' => 'beneficiary',
            'entity_uuid' => $beneficiary->global_uuid,
            'operation' => 'updated',
            'payload' => ['notes' => 'Offline version wins.', 'location_code' => '1900702001'],
            'status' => SyncRequestItem::STATUS_AWAITING_REVIEW,
            'conflict_uuid' => $conflict->conflict_uuid,
        ]);

        $this->actingAs($admin)
            ->post(route('sync-conflicts.resolve', $conflict), ['action' => 'accept_local'])
            ->assertRedirect();

        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->assertSame(SyncRequestItem::STATUS_ACCEPTED, $item->fresh()->status);

        // The access token stored for the central path... the operation receipt is written.
        $this->assertDatabaseHas('sync_changes', [
            'change_uuid' => $operationUuid,
            'entity_type' => 'beneficiary',
        ]);

        $inbox = $this->actingAs($admin)->get(route('review-inbox.index'))->assertOk();
        $this->assertSame(0, $inbox->viewData('counts')['total']);
    }

    public function test_admin_can_list_open_conflicts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'origin_server_uuid' => $this->server->server_uuid,
            'local_payload' => ['notes' => 'Local'],
            'remote_payload' => ['notes' => 'Central'],
            'diff' => ['notes'],
            'status' => 'open',
        ]);

        $this->actingAs($admin)
            ->get(route('sync-conflicts.index'))
            ->assertOk()
            ->assertSee('Open');
    }

    public function test_admin_can_view_conflict_detail(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $conflict = SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'origin_server_uuid' => $this->server->server_uuid,
            'local_payload' => ['notes' => 'Local note'],
            'remote_payload' => ['notes' => 'Central note'],
            'diff' => ['notes'],
            'status' => 'open',
        ]);

        $this->actingAs($admin)
            ->get(route('sync-conflicts.show', $conflict))
            ->assertOk()
            ->assertSee('notes')
            ->assertSee('Local note')
            ->assertSee('Central note');
    }

    public function test_admin_can_resolve_conflict_by_keeping_central(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $beneficiary = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'notes' => 'Central note stays.',
        ]);
        $beneficiary->forceFill(['conflict_status' => 'open'])->save();

        $conflict = SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => $beneficiary->global_uuid,
            'origin_server_uuid' => $this->server->server_uuid,
            'local_payload' => ['notes' => 'Offline note'],
            'remote_payload' => ['notes' => 'Central note stays.'],
            'diff' => ['notes'],
            'status' => 'open',
        ]);

        $this->actingAs($admin)
            ->post(route('sync-conflicts.resolve', $conflict), ['action' => 'keep_central'])
            ->assertRedirect(route('sync-conflicts.index', ['source' => 'server']));

        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->assertSame('keep_central', $conflict->fresh()->resolution['action']);

        // Central record unchanged.
        $this->assertSame('Central note stays.', $beneficiary->fresh()->notes);

        // conflict_status cleared.
        $this->assertSame('none', $beneficiary->fresh()->conflict_status);
    }

    public function test_admin_can_resolve_conflict_by_accepting_local(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $beneficiary = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'notes' => 'Old central note.',
        ]);
        $beneficiary->forceFill(['conflict_status' => 'open'])->save();

        $conflict = SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => $beneficiary->global_uuid,
            'origin_server_uuid' => $this->server->server_uuid,
            'local_payload' => ['notes' => 'Offline version wins.'],
            'remote_payload' => ['notes' => 'Old central note.'],
            'diff' => ['notes'],
            'status' => 'open',
        ]);

        $this->actingAs($admin)
            ->post(route('sync-conflicts.resolve', $conflict), ['action' => 'accept_local'])
            ->assertRedirect(route('sync-conflicts.index', ['source' => 'server']));

        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->assertSame('Offline version wins.', $beneficiary->fresh()->notes);
        $this->assertSame('none', $beneficiary->fresh()->conflict_status);

        // A conflict.resolved event was created.
        $this->assertSame(1, SyncEvent::where('type', 'conflict.resolved')->count());
    }

    public function test_admin_can_resolve_conflict_by_merging_selected_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $beneficiary = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'notes' => 'Old central note.',
            'occupation' => 'Farmer',
        ]);
        $beneficiary->forceFill(['conflict_status' => 'open'])->save();

        $conflict = SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => $beneficiary->global_uuid,
            'origin_server_uuid' => $this->server->server_uuid,
            'local_payload' => [
                'notes' => 'Offline note.',
                'occupation' => 'Fisher',
            ],
            'remote_payload' => [
                'notes' => 'Old central note.',
                'occupation' => 'Farmer',
            ],
            'diff' => ['notes', 'occupation'],
            'status' => 'open',
        ]);

        $this->actingAs($admin)
            ->post(route('sync-conflicts.resolve', $conflict), [
                'action' => 'merge',
                'fields' => ['notes'],
            ])
            ->assertRedirect(route('sync-conflicts.index', ['source' => 'server']));

        $this->assertSame('resolved', $conflict->fresh()->status);

        // Only notes was merged (not occupation).
        $this->assertSame('Offline note.', $beneficiary->fresh()->notes);
        $this->assertSame('Farmer', $beneficiary->fresh()->occupation); // central preserved
    }

    public function test_resolving_already_resolved_conflict_returns_validation_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $beneficiary = Beneficiary::factory()->create(['location_code' => '1900702001']);

        $conflict = SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => $beneficiary->global_uuid,
            'origin_server_uuid' => $this->server->server_uuid,
            'local_payload' => ['notes' => 'Old'],
            'remote_payload' => ['notes' => 'New'],
            'diff' => ['notes'],
            'status' => 'resolved', // already done
        ]);

        $this->actingAs($admin)
            ->post(route('sync-conflicts.resolve', $conflict), ['action' => 'keep_central'])
            ->assertSessionHasErrors('resolution');
    }

    // -------------------------------------------------------------------------
    // Offline (local) conflict-review UI
    // -------------------------------------------------------------------------

    public function test_offline_manager_cannot_access_conflict_resolution(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $manager = User::factory()->create(['role' => 'manager']);

        SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => null,
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'local_payload' => ['notes' => 'Offline'],
            'remote_payload' => ['notes' => 'Central'],
            'diff' => ['notes'],
            'status' => 'open',
        ]);

        $this->actingAs($manager)
            ->get(route('sync-conflicts.index', ['source' => 'local']))
            ->assertForbidden();
    }

    public function test_the_conflict_history_pages_use_the_shared_dashboard_layout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $conflict = SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'origin_server_uuid' => $this->server->server_uuid,
            'local_payload' => ['notes' => 'Local note'],
            'remote_payload' => ['notes' => 'Central note'],
            'diff' => ['notes'],
            'status' => 'open',
        ]);

        $this->actingAs($admin)->get(route('sync-conflicts.index'))
            ->assertOk()
            ->assertSee('Conflict history')
            ->assertSee('Open');

        $this->actingAs($admin)->get(route('sync-conflicts.show', $conflict))
            ->assertOk()
            ->assertSee('Conflict Review')
            ->assertSee('Review this decision');
    }
}
