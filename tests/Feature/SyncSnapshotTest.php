<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\Location;
use App\Models\OfflineServer;
use App\Models\SyncSnapshot;
use App\Services\Sync\ScopeService;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $scopes
     * @return array{server: OfflineServer, token: string}
     */
    private function enrollServer(array $scopes = []): array
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $response = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Snapshot Test Server',
            'server_uuid' => (string) Str::uuid(),
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'city_municipality',
            'scope_code' => '1900702000',
        ])->assertCreated();

        $data = $response->json('data');
        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();

        if ($scopes !== []) {
            app(ScopeService::class)->replace($server, $scopes);
        }

        return ['server' => $server, 'token' => $data['server_token']];
    }

    private function location(string $code, string $barangay): Location
    {
        return Location::query()->create([
            'psgc_code' => $code,
            'barangay' => $barangay,
            'city_municipality' => 'LAMITAN CITY',
            'province' => 'BASILAN',
            'full_address' => $barangay.', LAMITAN CITY, BASILAN',
            'is_city' => false,
        ]);
    }

    public function test_snapshot_manifest_and_beneficiary_pages_are_scoped_paginated_and_resumable(): void
    {
        ['token' => $token] = $this->enrollServer([
            ['scope_type' => 'city_municipality', 'location_code' => '1900702000'],
        ]);

        $this->location('1900702001', 'ARCO');
        $this->location('2000000001', 'OUTSIDE');

        $first = Beneficiary::factory()->create(['location_code' => '1900702001']);
        $second = Beneficiary::factory()->create(['location_code' => '1900702002']);
        $outside = Beneficiary::factory()->create(['location_code' => '2000000001']);

        $manifest = $this->withHeader((string) config('sync.server_token_header'), $token)
            ->getJson('/api/sync/v1/snapshot')
            ->assertCreated()
            ->assertJsonPath('data.manifest.beneficiaries', 2)
            ->assertJsonPath('data.manifest.locations', 1)
            ->json('data');

        $pageOne = $this->withHeader((string) config('sync.server_token_header'), $token)
            ->getJson('/api/sync/v1/snapshot/'.$manifest['snapshot_uuid'].'/beneficiaries?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->json();

        $this->assertSame($first->global_uuid, $pageOne['data'][0]['global_uuid']);

        $pageTwo = $this->withHeader((string) config('sync.server_token_header'), $token)
            ->getJson('/api/sync/v1/snapshot/'.$manifest['snapshot_uuid'].'/beneficiaries?limit=1&cursor='.$pageOne['meta']['next_cursor'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->json();

        $this->assertSame($second->global_uuid, $pageTwo['data'][0]['global_uuid']);
        $this->assertNotContains($outside->global_uuid, [
            $pageOne['data'][0]['global_uuid'],
            $pageTwo['data'][0]['global_uuid'],
        ]);
    }

    public function test_snapshot_reference_returns_scoped_locations_and_batch_headers(): void
    {
        ['token' => $token] = $this->enrollServer([
            ['scope_type' => 'city_municipality', 'location_code' => '1900702000'],
        ]);

        $this->location('1900702001', 'ARCO');
        $this->location('2000000001', 'OUTSIDE');
        $managerUuid = (string) Str::uuid();
        $batch = BeneficiaryBatch::factory()->create(['manager_uuid' => $managerUuid]);

        $manifest = $this->withHeader((string) config('sync.server_token_header'), $token)
            ->getJson('/api/sync/v1/snapshot')
            ->assertCreated()
            ->json('data');

        $this->withHeader((string) config('sync.server_token_header'), $token)
            ->getJson('/api/sync/v1/snapshot/'.$manifest['snapshot_uuid'].'/reference')
            ->assertOk()
            ->assertJsonCount(1, 'data.locations')
            ->assertJsonPath('data.locations.0.psgc_code', '1900702001')
            ->assertJsonPath('data.batches.0.global_uuid', $batch->global_uuid)
            ->assertJsonPath('data.batches.0.manager_uuid', $managerUuid);
    }

    public function test_delta_changes_are_scoped_and_paginated(): void
    {
        ['token' => $token] = $this->enrollServer([
            ['scope_type' => 'city_municipality', 'location_code' => '1900702000'],
        ]);

        $inScope = Beneficiary::factory()->create(['location_code' => '1900702001']);
        Beneficiary::factory()->create(['location_code' => '2000000001']);

        $pageOne = $this->withHeader((string) config('sync.server_token_header'), $token)
            ->getJson('/api/sync/v1/changes?since=0&limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->json();

        $this->assertSame($inScope->global_uuid, $pageOne['data'][0]['entity_uuid']);

        $inScope->update(['notes' => 'Second change']);

        $this->withHeader((string) config('sync.server_token_header'), $token)
            ->getJson('/api/sync/v1/changes?since='.$pageOne['meta']['next_cursor'].'&limit=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entity_uuid', $inScope->global_uuid)
            ->assertJsonPath('data.0.operation', 'updated');
    }

    public function test_snapshots_are_private_to_the_authenticated_server(): void
    {
        ['token' => $tokenA] = $this->enrollServer([
            ['scope_type' => 'city_municipality', 'location_code' => '1900702000'],
        ]);
        ['token' => $tokenB] = $this->enrollServer([
            ['scope_type' => 'city_municipality', 'location_code' => '1900702000'],
        ]);

        $snapshotUuid = $this->withHeader((string) config('sync.server_token_header'), $tokenA)
            ->getJson('/api/sync/v1/snapshot')
            ->assertCreated()
            ->json('data.snapshot_uuid');

        $this->withHeader((string) config('sync.server_token_header'), $tokenB)
            ->getJson('/api/sync/v1/snapshot/'.$snapshotUuid.'/beneficiaries')
            ->assertNotFound();
    }

    public function test_expired_snapshots_return_410(): void
    {
        ['token' => $token] = $this->enrollServer([
            ['scope_type' => 'city_municipality', 'location_code' => '1900702000'],
        ]);

        $snapshotUuid = $this->withHeader((string) config('sync.server_token_header'), $token)
            ->getJson('/api/sync/v1/snapshot')
            ->assertCreated()
            ->json('data.snapshot_uuid');

        SyncSnapshot::query()->where('snapshot_uuid', $snapshotUuid)->firstOrFail()
            ->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withHeader((string) config('sync.server_token_header'), $token)
            ->getJson('/api/sync/v1/snapshot/'.$snapshotUuid.'/beneficiaries')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'snapshot_expired');
    }
}
