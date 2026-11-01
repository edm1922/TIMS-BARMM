<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\OfflineServer;
use App\Models\User;
use App\Services\Sync\SyncReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private OfflineServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sync.instance_mode' => 'online']);

        $this->server = OfflineServer::factory()->create();
        $this->server->scopes()->create([
            'scope_type' => 'city_municipality',
            'location_code' => '1900702000',
        ]);
    }

    /**
     * Report a batch roster for the server as a heartbeat would deliver it.
     *
     * @param  array<int, array<string, mixed>>  $batches
     */
    private function reportRoster(array $batches): void
    {
        $this->server->forceFill([
            'metadata' => [
                'last_roster' => [
                    'captured_at' => now()->toIso8601String(),
                    'batches' => $batches,
                ],
            ],
        ])->save();

        $this->server->refresh();
    }

    private function inScopeBeneficiary(BeneficiaryBatch $batch): Beneficiary
    {
        return Beneficiary::factory()->create([
            'beneficiary_batch_id' => $batch->id,
            'location_code' => '1900702001',
        ]);
    }

    // Scenario A: central has records the installation does not report.
    public function test_central_records_missing_from_the_installation_are_flagged(): void
    {
        $batch = BeneficiaryBatch::factory()->create(['name' => 'South Upi']);
        $this->inScopeBeneficiary($batch);
        $this->inScopeBeneficiary($batch);

        $this->reportRoster([
            ['uuid' => $batch->global_uuid, 'name' => 'South Upi', 'deleted' => false, 'members' => 0],
        ]);

        $report = app(SyncReconciliationService::class)->forServer($this->server);

        $this->assertTrue($report['roster_available']);
        $this->assertSame(1, $report['totals']['missing_on_offline']);
        $this->assertSame(2, $report['batches'][0]['central_members']);
        $this->assertSame(0, $report['batches'][0]['offline_members']);
        $this->assertSame(2, $report['batches'][0]['delta']);
    }

    // Scenario C: both sides report the same batch state.
    public function test_matching_batches_are_in_sync(): void
    {
        $batch = BeneficiaryBatch::factory()->create(['name' => 'South Upi']);
        $this->inScopeBeneficiary($batch);

        $this->reportRoster([
            ['uuid' => $batch->global_uuid, 'name' => 'South Upi', 'deleted' => false, 'members' => 1],
        ]);

        $report = app(SyncReconciliationService::class)->forServer($this->server);

        $this->assertSame(1, $report['totals']['in_sync']);
        $this->assertSame(0, $report['batches'][0]['delta']);
    }

    // Scenario B/G: the installation reports a batch central has never seen.
    public function test_a_batch_unknown_to_central_is_flagged_for_backfill(): void
    {
        $this->reportRoster([
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Locally Created Batch',
                'deleted' => false,
                'members' => 100,
            ],
        ]);

        $report = app(SyncReconciliationService::class)->forServer($this->server);

        $this->assertSame(1, $report['totals']['unknown_to_central']);
        $this->assertSame(null, $report['batches'][0]['central_members']);
        $this->assertSame(100, $report['batches'][0]['offline_members']);
    }

    public function test_the_unassigned_bucket_is_reported_one_sided(): void
    {
        Beneficiary::factory()->create(['location_code' => '1900702001']);

        // The installation reports nothing outside batches (empty unassigned),
        // but central holds one unassigned in-scope record.
        $this->reportRoster([
            ['uuid' => null, 'name' => 'Not in any batch', 'deleted' => false, 'members' => 0],
        ]);

        $report = app(SyncReconciliationService::class)->forServer($this->server);

        $unassigned = $report['batches'][0];

        $this->assertNull($unassigned['uuid']);
        $this->assertSame(1, $unassigned['central_members']);
        $this->assertSame(0, $unassigned['offline_members']);
        $this->assertSame(1, $report['totals']['unassigned_central']);
    }

    public function test_central_records_inside_deleted_batches_are_counted_as_ghosted(): void
    {
        $batch = BeneficiaryBatch::factory()->create();
        $batch->delete(); // soft-delete the batch; members stay active

        Beneficiary::factory()->create([
            'beneficiary_batch_id' => $batch->id,
            'location_code' => '1900702001',
        ]);

        $this->reportRoster([]);

        $report = app(SyncReconciliationService::class)->forServer($this->server);

        $this->assertSame(1, $report['totals']['ghosted_central']);
    }

    public function test_the_delivery_backlog_counts_unacknowledged_in_scope_changes(): void
    {
        $batch = BeneficiaryBatch::factory()->create();
        $inScope = $this->inScopeBeneficiary($batch);
        $outOfScope = Beneficiary::factory()->create(['location_code' => '9999999999']);

        // Factory-created rows are auto-recorded into the change log; start
        // from a clean log so the seeded rows are the only evidence.
        DB::table('sync_changes')->delete();

        DB::table('sync_changes')->insert([
            [
                'change_uuid' => (string) Str::uuid(),
                'entity_type' => 'beneficiary',
                'entity_uuid' => $inScope->global_uuid,
                'operation' => 'created',
                'origin_server_uuid' => '00000000-0000-4000-8000-000000000001',
                'revision' => 1,
                'payload' => json_encode(['data' => []]),
                'status' => 'pending',
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'change_uuid' => (string) Str::uuid(),
                'entity_type' => 'beneficiary',
                'entity_uuid' => $outOfScope->global_uuid,
                'operation' => 'created',
                'origin_server_uuid' => '00000000-0000-4000-8000-000000000001',
                'revision' => 1,
                'payload' => json_encode(['data' => []]),
                'status' => 'pending',
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // The server has acknowledged nothing: only the in-scope change is
        // its backlog. The out-of-scope record never applies to this server.
        $this->reportRoster([]);

        $report = app(SyncReconciliationService::class)->forServer($this->server);

        $this->assertSame(1, $report['delivery_backlog']);
    }

    public function test_the_delivery_backlog_only_counts_changes_beyond_the_acked_cursor(): void
    {
        $batch = BeneficiaryBatch::factory()->create();
        $member = $this->inScopeBeneficiary($batch);

        $acked = DB::table('sync_changes')->insertGetId([
            'change_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $member->global_uuid,
            'operation' => 'created',
            'origin_server_uuid' => '00000000-0000-4000-8000-000000000001',
            'revision' => 1,
            'payload' => json_encode(['data' => []]),
            'status' => 'pending',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The server acknowledged everything up to and including that change.
        $this->server->checkpoints()->create([
            'direction' => 'download',
            'last_revision' => 1,
            'cursor' => (string) $acked,
            'synced_at' => now(),
        ]);

        $this->reportRoster([]);
        $report = app(SyncReconciliationService::class)->forServer($this->server);

        $this->assertSame(0, $report['delivery_backlog']);
    }

    public function test_the_server_page_renders_the_reconciliation_card(): void
    {
        $batch = BeneficiaryBatch::factory()->create(['name' => 'South Upi']);
        $this->inScopeBeneficiary($batch);

        $this->reportRoster([
            ['uuid' => $batch->global_uuid, 'name' => 'South Upi', 'deleted' => false, 'members' => 0],
        ]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('sync-servers.show', $this->server))
            ->assertOk()
            ->assertSee('Reconciliation')
            ->assertSee('South Upi')
            ->assertSee('Missing on this installation');

        // Without a roster the page degrades gracefully.
        $this->server->forceFill(['metadata' => null])->save();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('sync-servers.show', $this->server))
            ->assertOk()
            ->assertSee('No batch roster reported yet');
    }
}
