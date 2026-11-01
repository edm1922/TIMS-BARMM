<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\OfflineServer;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\Sync\LocalChangeApplier;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Multiple offline installations synchronize independently against one
 * central master: an approved deletion propagates to every *other*
 * applicable installation, and scope filtering keeps each installation's
 * feed limited to its own coverage.
 */
class MultiServerSyncTest extends TestCase
{
    use RefreshDatabase;

    private OfflineServer $serverA;

    private OfflineServer $serverB;

    private string $tokenA;

    private string $tokenB;

    private string $currentToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        config(['sync.instance_mode' => 'online']);

        // Two installations covering the same municipality: a deletion
        // approved for one must reach the other as well.
        $this->serverA = $this->enroll('Installation A');
        $this->tokenA = $this->currentToken;
        $this->serverB = $this->enroll('Installation B');
        $this->tokenB = $this->currentToken;
    }

    private function enroll(string $name): OfflineServer
    {
        $issued = app(TokenService::class)->generateEnrollmentToken(null, $name, 30);

        $enrolled = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => $name,
            'app_version' => '1.0.0',
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'city_municipality',
            'scope_code' => '1900702000',
        ])->assertCreated()->json('data');

        $this->currentToken = $enrolled['server_token'];

        return OfflineServer::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function postOperation(string $token, array $operation): array
    {
        $response = $this->withHeader((string) config('sync.server_token_header'), $token)
            ->postJson('/api/sync/v1/operations', ['operations' => [$operation]]);

        $response->assertOk();

        return $response->json('data.0');
    }

    public function test_an_approved_deletion_propagates_to_the_other_installation(): void
    {
        // One shared record. In production each installation holds its own
        // replica row under this global UUID; in a single-database test the
        // central row stands in for them, and propagation is verified by the
        // change-log delivery to the other installation's scoped feed.
        $central = Beneficiary::factory()->create([
            'first_name' => 'SHARED',
            'last_name' => 'RECORD',
            'location_code' => '1900702001',
        ]);

        // Installation A requests the deletion.
        $result = $this->postOperation($this->tokenA, [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $central->global_uuid,
            'operation' => 'deleted',
            'base_revision' => $central->revision,
            'payload' => ['delete_reason' => 'Duplicate of an existing record.'],
        ]);

        $this->assertSame('awaiting_review', $result['status']);

        // The central administrator approves it.
        $conflict = SyncConflict::query()->where('operation', 'deleted')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('sync-conflicts.resolve', $conflict), ['action' => 'approve_deletion'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The central master keeps an archived tombstone — never a hard delete.
        $central->refresh();
        $this->assertNotNull($central->archived_at);

        // Installation B downloads its delta and receives the tombstone.
        $feed = $this->withHeader((string) config('sync.server_token_header'), $this->tokenB)
            ->getJson('/api/sync/v1/changes?since=0')
            ->assertOk()
            ->json('data');

        $archiveChanges = collect($feed)->filter(function (array $change) use ($central): bool {
            return $change['entity_uuid'] === $central->global_uuid
                && in_array('archived_at', (array) data_get($change, 'payload.changed_fields', []), true);
        });

        $this->assertTrue($archiveChanges->isNotEmpty(), 'the archive tombstone must reach installation B');

        // Applying it is idempotent: 'applied' on a fresh replica, 'skipped'
        // when the row is already in the target state (as in this
        // single-database test) — never a duplicate or an error.
        foreach ($archiveChanges as $change) {
            $outcome = app(LocalChangeApplier::class)->apply($change);

            $this->assertContains($outcome, ['applied', 'skipped']);
        }

        $this->assertSame(1, Beneficiary::query()->where('global_uuid', $central->global_uuid)->count());
        $this->assertNotNull($central->fresh()->archived_at);

        // Installation A replays its deletion receipt: settled. A *new*
        // deletion request for the now-archived record is an idempotent
        // no-op — the requested end state already exists.
        $replay = $this->postOperation($this->tokenA, [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $central->global_uuid,
            'operation' => 'deleted',
            'base_revision' => $central->revision,
            'payload' => [],
        ]);

        $this->assertSame('applied', $replay['status']);
        $this->assertSame('already_archived', $replay['code']);
    }

    public function test_scope_filtering_keeps_other_installations_feeds_limited_to_their_coverage(): void
    {
        // Installation B additionally covers a second municipality; A does not.
        $this->serverB->scopes()->create([
            'scope_type' => 'city_municipality',
            'location_code' => '1900703000',
        ]);

        // A record inside both installations' shared coverage.
        $shared = Beneficiary::factory()->create(['location_code' => '1900702001']);
        // A record inside B's second coverage only.
        $inBOnly = Beneficiary::factory()->create(['location_code' => '1900703001']);
        // A record nobody covers.
        $uncovered = Beneficiary::factory()->create(['location_code' => '1900705001']);

        $feedA = $this->withHeader((string) config('sync.server_token_header'), $this->tokenA)
            ->getJson('/api/sync/v1/changes?since=0')
            ->json('data');
        $feedB = $this->withHeader((string) config('sync.server_token_header'), $this->tokenB)
            ->getJson('/api/sync/v1/changes?since=0')
            ->json('data');

        $feedAUuids = collect($feedA)->where('entity_type', 'beneficiary')->pluck('entity_uuid');
        $feedBUuids = collect($feedB)->where('entity_type', 'beneficiary')->pluck('entity_uuid');

        // A sees its own coverage; B's second coverage is not A's business.
        $this->assertTrue($feedAUuids->contains($shared->global_uuid));
        $this->assertFalse($feedAUuids->contains($inBOnly->global_uuid), 'records outside a server scope must not appear in its feed');
        $this->assertFalse($feedAUuids->contains($uncovered->global_uuid));

        // B sees both of its coverages and none of the uncovered record.
        $this->assertTrue($feedBUuids->contains($shared->global_uuid));
        $this->assertTrue($feedBUuids->contains($inBOnly->global_uuid));
        $this->assertFalse($feedBUuids->contains($uncovered->global_uuid));
    }
}
