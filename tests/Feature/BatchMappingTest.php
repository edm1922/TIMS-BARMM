<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\OfflineServer;
use App\Models\SyncConflict;
use App\Models\SyncRequestItem;
use App\Models\User;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Central-master batch integrity: creations that reference a batch the
 * central server has never seen are held as mapping conflicts instead of
 * being silently unbatched, and the administrator's mapping decision applies
 * the held records correctly.
 */
class BatchMappingTest extends TestCase
{
    use RefreshDatabase;

    private string $serverToken;

    private OfflineServer $server;

    private string $offlineBatchUuid;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sync.instance_mode' => 'online']);

        $issued = app(TokenService::class)->generateEnrollmentToken(null, 'Field Laptop', 30);

        $enrolled = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Field Laptop',
            'app_version' => '1.0.0',
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'city_municipality',
            'scope_code' => '1900702000',
        ])->assertCreated()->json('data');

        $this->server = OfflineServer::query()->latest('id')->firstOrFail();
        $this->serverToken = $enrolled['server_token'];
        $this->offlineBatchUuid = (string) Str::uuid();
    }

    /**
     * Submit a creation that belongs to the offline-only batch.
     *
     * @return array<string, mixed>
     */
    private function submitCreation(string $name, ?string $operationUuid = null): array
    {
        $response = $this->withHeader((string) config('sync.server_token_header'), $this->serverToken)
            ->postJson('/api/sync/v1/operations', [
                'operations' => [[
                    'operation_uuid' => $operationUuid ?? (string) Str::uuid(),
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => (string) Str::uuid(),
                    'operation' => 'created',
                    'base_revision' => 0,
                    'payload' => [
                        'first_name' => $name,
                        'last_name' => 'UPIMEMBER',
                        'location_code' => '1900702001',
                        'source_id_no' => 'GOV-'.md5($name),
                        'beneficiary_batch_uuid' => $this->offlineBatchUuid,
                    ],
                ]],
            ]);

        $response->assertOk();

        return $response->json('data.0');
    }

    public function test_a_creation_referencing_an_unknown_batch_is_held_for_mapping(): void
    {
        $result = $this->submitCreation('MAPPED');

        $this->assertSame('awaiting_review', $result['status']);
        $this->assertSame('batch_mapping_pending', $result['code']);
        $this->assertNotEmpty($result['conflict_uuid']);

        // The record was NOT created unbatched — nothing was written.
        $this->assertDatabaseCount('beneficiaries', 0);

        $conflict = SyncConflict::query()->latest('id')->firstOrFail();
        $this->assertSame('mapping', $conflict->operation);
        $this->assertSame($this->offlineBatchUuid, $conflict->entity_uuid);
        $this->assertSame('open', $conflict->status);

        // The held upload item points at its conflict, so the inbox shows the
        // decision once (as a batch mapping), not a second "Invalid record".
        $item = SyncRequestItem::query()
            ->where('operation_uuid', $result['operation_uuid'])
            ->firstOrFail();
        $this->assertSame($conflict->conflict_uuid, $item->conflict_uuid);

        $inbox = $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('review-inbox.index'))
            ->assertOk();

        $this->assertSame(1, $inbox->viewData('entries')->total());
        $this->assertSame('Batch mapping', $inbox->viewData('entries')->first()['label']);
    }

    public function test_held_members_of_the_same_batch_coalesce_into_one_conflict(): void
    {
        $first = $this->submitCreation('MEMBER');
        $this->submitCreation('OTHER');

        $conflict = SyncConflict::query()->where('operation', 'mapping')->get();
        $this->assertSame(1, $conflict->count());
        $this->assertSame($first['conflict_uuid'], $conflict->first()->conflict_uuid);
    }

    public function test_creating_the_batch_centrally_applies_the_held_records_into_it(): void
    {
        $first = $this->submitCreation('MEMBER');
        $this->submitCreation('OTHER');

        $conflict = SyncConflict::query()->where('operation', 'mapping')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('sync-conflicts.resolve', $conflict), [
                'action' => 'map_batch',
                'fields' => ['new_batch_name' => 'South Upi'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The central batch was created under the offline installation's
        // batch UUID, and both held records were applied into it.
        $centralBatch = BeneficiaryBatch::query()->where('global_uuid', $this->offlineBatchUuid)->firstOrFail();
        $this->assertSame('South Upi', $centralBatch->name);

        $this->assertSame(2, Beneficiary::query()->where('beneficiary_batch_id', $centralBatch->id)->count());

        $conflict->refresh();
        $this->assertSame('resolved', $conflict->status);

        $this->assertDatabaseHas('audit_logs', ['action' => 'beneficiary_batch.mapping_resolved']);

        // The held records were decided ACCEPTED, so the request-outcomes
        // channel delivers the settlement to the offline installation.
        $held = SyncRequestItem::query()
            ->where('payload->beneficiary_batch_uuid', $this->offlineBatchUuid)
            ->get();
        $this->assertSame(2, $held->count());
        $this->assertTrue($held->every(fn ($item) => $item->status === 'accepted'));

        $this->withHeader((string) config('sync.server_token_header'), $this->serverToken)
            ->getJson('/api/sync/v1/request-outcomes?since=0')
            ->assertOk()
            ->assertJsonPath('meta.count', 2);

        // Retrying the original operation replays the applied receipt.
        $replayed = $this->submitCreation('MEMBER', $first['operation_uuid'] ?? null);
        $this->assertNotSame('awaiting_review', $replayed['status']);
    }

    public function test_mapping_onto_an_existing_central_batch_moves_the_held_records(): void
    {
        $existing = BeneficiaryBatch::factory()->create(['name' => 'South Upi (central)']);

        $this->submitCreation('MEMBER');
        $this->submitCreation('OTHER');

        $conflict = SyncConflict::query()->where('operation', 'mapping')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('sync-conflicts.resolve', $conflict), [
                'action' => 'map_batch',
                'fields' => ['target_batch_id' => (string) $existing->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            2,
            Beneficiary::query()->where('beneficiary_batch_id', $existing->id)->count(),
        );
        $this->assertNull(BeneficiaryBatch::query()->where('global_uuid', $this->offlineBatchUuid)->first());
    }

    public function test_discarding_leaves_the_records_on_the_offline_installation(): void
    {
        $this->submitCreation('MEMBER');

        $conflict = SyncConflict::query()->where('operation', 'mapping')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('sync-conflicts.resolve', $conflict), ['action' => 'discard_batch'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('beneficiaries', 0);
        $conflict->refresh();
        $this->assertSame('resolved', $conflict->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'beneficiary_batch.mapping_discarded']);
    }

    public function test_creations_without_a_batch_uuid_are_unaffected(): void
    {
        $response = $this->withHeader((string) config('sync.server_token_header'), $this->serverToken)
            ->postJson('/api/sync/v1/operations', [
                'operations' => [[
                    'operation_uuid' => (string) Str::uuid(),
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => (string) Str::uuid(),
                    'operation' => 'created',
                    'base_revision' => 0,
                    'payload' => [
                        'first_name' => 'NOBATCH',
                        'last_name' => 'MEMBER',
                        'location_code' => '1900702001',
                        'source_id_no' => 'GOV-NOBATCH-1',
                    ],
                ]],
            ]);

        $response->assertOk();
        $this->assertSame('applied', $response->json('data.0.status'));
        $this->assertSame(1, Beneficiary::query()->count());
    }
}
