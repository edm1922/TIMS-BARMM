<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\Location;
use App\Models\OfflineServer;
use App\Models\SyncChange;
use App\Models\SyncOperation;
use App\Services\Sync\LocalChangeApplier;
use App\Services\Sync\ScopeService;
use App\Services\Sync\SyncPayload;
use App\Support\SyncIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class SyncFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_beneficiaries_receive_sync_identity_and_record_changes(): void
    {
        $beneficiary = Beneficiary::factory()->create();

        $this->assertNotNull($beneficiary->global_uuid);
        $this->assertSame((string) config('sync.central_server_uuid'), $beneficiary->origin_server_uuid);
        $this->assertSame(1, $beneficiary->revision);
        $this->assertSame('synced', $beneficiary->sync_status);
        $this->assertSame('none', $beneficiary->conflict_status);

        $created = SyncChange::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $beneficiary->global_uuid)
            ->where('operation', 'created')
            ->firstOrFail();

        $this->assertSame($beneficiary->id, $created->local_id);

        $beneficiary->update(['notes' => 'Updated during sync test.']);

        $updated = SyncChange::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $beneficiary->global_uuid)
            ->where('operation', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertContains('notes', $updated->payload['changed_fields']);
    }

    public function test_batches_receive_sync_identity_and_use_soft_delete_tombstones(): void
    {
        $batch = BeneficiaryBatch::factory()->create();

        $this->assertNotNull($batch->global_uuid);
        $this->assertSame((string) config('sync.central_server_uuid'), $batch->origin_server_uuid);
        $this->assertSame(1, $batch->revision);

        $batch->delete();

        $this->assertSoftDeleted('beneficiary_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('sync_changes', [
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $batch->global_uuid,
            'operation' => 'deleted',
        ]);
    }

    public function test_batch_names_are_unique_within_an_origin_but_can_repeat_across_origins(): void
    {
        BeneficiaryBatch::query()->create([
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Shared Batch Name',
            'status' => BeneficiaryBatch::STATUS_OPEN,
        ]);

        BeneficiaryBatch::query()->create([
            'origin_server_uuid' => '22222222-2222-4222-8222-222222222222',
            'name' => 'Shared Batch Name',
            'status' => BeneficiaryBatch::STATUS_OPEN,
        ]);

        $this->assertSame(2, BeneficiaryBatch::query()->where('name', 'Shared Batch Name')->count());

        $this->expectException(QueryException::class);

        BeneficiaryBatch::query()->create([
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Shared Batch Name',
            'status' => BeneficiaryBatch::STATUS_OPEN,
        ]);
    }

    public function test_a_batch_name_collision_is_recorded_as_a_conflict_instead_of_aborting_sync(): void
    {
        $local = BeneficiaryBatch::query()->create([
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'name' => 'test',
            'status' => BeneficiaryBatch::STATUS_OPEN,
        ]);

        SyncOperation::query()->delete();

        $result = app(LocalChangeApplier::class)->apply([
            'change_uuid' => '33333333-3333-4333-8333-333333333333',
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => '44444444-4444-4444-8444-444444444444',
            'operation' => 'created',
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'revision' => 1,
            'payload' => [
                'changed_fields' => ['name'],
                'data' => [
                    'name' => 'test',
                    'description' => null,
                    'status' => BeneficiaryBatch::STATUS_OPEN,
                ],
            ],
        ]);

        $this->assertSame('conflict', $result);
        $this->assertSame(1, BeneficiaryBatch::query()->where('name', 'test')->count());
        $this->assertDatabaseHas('sync_events', [
            'type' => 'conflict',
            'severity' => 'warning',
        ]);
    }

    public function test_batch_manager_uuid_is_synced_and_available_to_the_offline_installation(): void
    {
        $managerUuid = (string) Str::uuid();
        $batch = BeneficiaryBatch::query()->create([
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Managed Sync Batch',
            'status' => BeneficiaryBatch::STATUS_OPEN,
            'manager_uuid' => $managerUuid,
        ]);

        $this->assertSame($managerUuid, SyncPayload::for($batch)['manager_uuid']);

        $result = app(LocalChangeApplier::class)->apply([
            'change_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $batch->global_uuid,
            'operation' => 'updated',
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'revision' => 2,
            'payload' => [
                'changed_fields' => ['manager_uuid'],
                'data' => [
                    'name' => 'Managed Sync Batch',
                    'description' => null,
                    'status' => BeneficiaryBatch::STATUS_OPEN,
                    'manager_uuid' => $managerUuid,
                ],
            ],
        ]);

        $this->assertSame('applied', $result);
        $this->assertSame($managerUuid, $batch->refresh()->manager_uuid);
    }

    public function test_manager_assignment_is_applied_even_when_the_batch_originated_on_this_server(): void
    {
        $origin = SyncIdentity::originUuid();
        $managerUuid = (string) Str::uuid();
        $batch = BeneficiaryBatch::query()->create([
            'origin_server_uuid' => $origin,
            'name' => 'Locally Originated Batch',
            'status' => BeneficiaryBatch::STATUS_OPEN,
        ]);

        $result = app(LocalChangeApplier::class)->apply([
            'change_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $batch->global_uuid,
            'operation' => 'updated',
            'origin_server_uuid' => $origin,
            'revision' => 2,
            'payload' => [
                'changed_fields' => ['manager_uuid'],
                'data' => [
                    'name' => 'Locally Originated Batch',
                    'description' => null,
                    'status' => BeneficiaryBatch::STATUS_OPEN,
                    'manager_uuid' => $managerUuid,
                ],
            ],
        ]);

        $this->assertSame('applied', $result);
        $this->assertSame($managerUuid, $batch->refresh()->manager_uuid);
    }

    public function test_scope_service_matches_psgc_prefixes_and_filters_beneficiaries(): void
    {
        $server = OfflineServer::factory()->create();
        $scopes = app(ScopeService::class);

        $scopes->replace($server, [
            ['scope_type' => 'province', 'location_code' => '1900000000', 'province' => 'BASILAN'],
            ['scope_type' => 'city_municipality', 'location_code' => '1900702000', 'city_municipality' => 'LAMITAN CITY'],
        ]);

        $inScope = Beneficiary::factory()->create(['location_code' => '1900702001']);
        $outOfScope = Beneficiary::factory()->create(['location_code' => '2000000001']);
        Beneficiary::factory()->create(['location_code' => null]);

        $this->assertTrue($scopes->allowsLocationCode($server, '1900702001'));
        $this->assertTrue($scopes->allowsLocationCode($server, '1900702002'));
        $this->assertTrue($scopes->allowsLocationCode($server, '1900708001'));
        $this->assertFalse($scopes->allowsLocationCode($server, '2000000001'));
        $this->assertFalse($scopes->allowsLocationCode($server, null));

        $ids = $scopes->applyToBeneficiaryQuery(Beneficiary::query(), $server)->pluck('id')->all();

        $this->assertContains($inScope->id, $ids);
        $this->assertNotContains($outOfScope->id, $ids);
    }

    public function test_a_server_without_scopes_receives_no_beneficiary_rows(): void
    {
        $server = OfflineServer::factory()->create();
        Beneficiary::factory()->create(['location_code' => '1900001001']);

        $ids = app(ScopeService::class)
            ->applyToBeneficiaryQuery(Beneficiary::query(), $server)
            ->pluck('id')
            ->all();

        $this->assertSame([], $ids);
    }

    public function test_a_region_scope_covers_every_barm_location(): void
    {
        $this->seedLocations();

        $server = OfflineServer::factory()->create();
        $scopes = app(ScopeService::class);

        $scopes->replace($server, [
            ['scope_type' => 'region', 'location_code' => 'BARMM'],
        ]);

        $this->assertSame(['1900', '1908'], $scopes->prefixes($server));
        $this->assertTrue($scopes->allowsLocationCode($server, '1900702001'));
        $this->assertTrue($scopes->allowsLocationCode($server, '1908708001'));
        $this->assertFalse($scopes->allowsLocationCode($server, '2000000001'));

        $this->assertSame(2, $scopes->applyToLocationQuery(Location::query(), $server)->count());
    }

    public function test_replace_rejects_a_scope_that_matches_no_barm_location(): void
    {
        $this->seedLocations();

        $server = OfflineServer::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(ScopeService::class)->replace($server, [
            ['scope_type' => 'province', 'location_code' => '3453'],
        ]);
    }

    private function seedLocations(): void
    {
        Location::query()->create([
            'psgc_code' => '1900702001',
            'barangay' => 'Poblacion',
            'city_municipality' => 'Lamitan City',
            'province' => 'BASILAN',
            'full_address' => 'Poblacion, Lamitan City, BASILAN',
            'is_city' => true,
        ]);

        Location::query()->create([
            'psgc_code' => '1908708001',
            'barangay' => 'Poblacion',
            'city_municipality' => 'Buluan',
            'province' => 'MAGUINDANAO DEL SUR',
            'full_address' => 'Poblacion, Buluan, MAGUINDANAO DEL SUR',
            'is_city' => true,
        ]);
    }
}
