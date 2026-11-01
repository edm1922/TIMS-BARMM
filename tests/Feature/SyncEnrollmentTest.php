<?php

namespace Tests\Feature;

use App\Models\OfflineServer;
use App\Models\OfflineServerEnrollmentToken;
use App\Models\User;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentPayload(string $token, array $overrides = []): array
    {
        return array_merge([
            'enrollment_token' => $token,
            'name' => 'Municipal Offline Server',
            'app_version' => '1.0.0',
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'province',
            'scope_code' => '1900702000',
        ], $overrides);
    }

    private function enroll(string $token, array $overrides = []): array
    {
        $response = $this->postJson('/api/sync/v1/enroll', $this->enrollmentPayload($token, $overrides));
        $response->assertCreated();

        return $response->json('data');
    }

    public function test_compatibility_endpoint_reports_supported_versions(): void
    {
        $this->getJson('/api/sync/v1/compatibility')
            ->assertOk()
            ->assertJsonPath('data.protocol_version', 1)
            ->assertJsonPath('data.schema_version', 1)
            ->assertJsonPath('data.instance_mode', 'online');
    }

    public function test_enrollment_consumes_the_one_time_token_and_returns_a_server_token_once(): void
    {
        $admin = $this->admin();
        $issued = app(TokenService::class)->generateEnrollmentToken($admin->id);

        $data = $this->enroll($issued['token']);

        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();

        $this->assertSame(hash('sha256', $data['server_token']), $server->api_token_hash);
        $this->assertNotSame($data['server_token'], $server->api_token_hash);
        $this->assertSame($admin->id, $server->created_by);
        $this->assertNotNull($server->enrolled_at);

        $this->assertDatabaseHas('offline_server_enrollment_tokens', [
            'id' => $issued['id'],
            'used_by_server_id' => $server->id,
        ]);

        $this->assertNotNull(OfflineServerEnrollmentToken::query()->findOrFail($issued['id'])->used_at);
    }

    public function test_enrollment_without_a_scope_covers_the_entire_barmm_region(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $payload = $this->enrollmentPayload($issued['token']);
        unset($payload['scope_type'], $payload['scope_code']);

        $response = $this->postJson('/api/sync/v1/enroll', $payload)->assertCreated();

        $server = OfflineServer::query()
            ->where('server_uuid', $response->json('data.server_uuid'))
            ->firstOrFail();

        $this->assertDatabaseHas('offline_server_scopes', [
            'offline_server_id' => $server->id,
            'scope_type' => 'region',
            'location_code' => 'BARMM',
        ]);
    }

    public function test_a_named_scope_requires_a_psgc_code(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $payload = $this->enrollmentPayload($issued['token']);
        unset($payload['scope_code']);

        $this->postJson('/api/sync/v1/enroll', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_scope');
    }

    public function test_a_used_enrollment_token_cannot_enroll_a_second_server(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $this->enroll($issued['token']);

        $this->postJson('/api/sync/v1/enroll', $this->enrollmentPayload($issued['token']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_enrollment_token');

        $this->assertDatabaseCount('offline_servers', 1);
    }

    public function test_an_expired_enrollment_token_is_rejected_without_creating_a_server(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        OfflineServerEnrollmentToken::query()->findOrFail($issued['id'])->forceFill([
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->postJson('/api/sync/v1/enroll', $this->enrollmentPayload($issued['token']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_enrollment_token');

        $this->assertDatabaseCount('offline_servers', 0);
    }

    public function test_unsupported_versions_return_426_without_consuming_the_token(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $this->postJson('/api/sync/v1/enroll', $this->enrollmentPayload($issued['token'], [
            'protocol_version' => 99,
        ]))
            ->assertStatus(426)
            ->assertJsonPath('error.code', 'unsupported_sync_version')
            ->assertJsonPath('compatibility.protocol_version', 1);

        $this->assertNull(OfflineServerEnrollmentToken::query()->findOrFail($issued['id'])->used_at);
    }

    public function test_heartbeat_authenticates_with_the_server_token(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/heartbeat', ['app_version' => '1.0.1'])
            ->assertOk()
            ->assertJsonPath('data.server_uuid', $data['server_uuid'])
            ->assertJsonPath('data.status', 'active');

        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();

        $this->assertSame('1.0.1', $server->app_version);
        $this->assertNotNull($server->last_seen_at);
        $this->assertNotNull($server->api_token_last_used_at);
    }

    public function test_heartbeat_rejects_missing_and_unknown_tokens(): void
    {
        $this->postJson('/api/sync/v1/heartbeat')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_server_token');

        $this->withHeader((string) config('sync.server_token_header'), 'not-a-real-token')
            ->postJson('/api/sync/v1/heartbeat')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_server_token');
    }

    public function test_suspended_servers_are_rejected(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail()
            ->forceFill(['status' => OfflineServer::STATUS_SUSPENDED])->save();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/heartbeat')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'server_inactive');
    }

    public function test_revoked_server_tokens_are_rejected(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();
        app(TokenService::class)->revoke($server);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/heartbeat')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_server_token');

        $this->assertSame(OfflineServer::STATUS_REVOKED, $server->fresh()->status);
    }

    public function test_reauthorization_of_a_revoked_server_explains_that_re_enrollment_is_required(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();
        app(TokenService::class)->revoke($server);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/reauthorize', ['reauthorization_token' => 'anything'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_server_token')
            ->assertJsonPath('error.message', fn (string $message): bool => str_contains($message, 're-enroll'));
    }

    public function test_a_revoked_server_can_re_enroll_with_a_new_enrollment_token(): void
    {
        $first = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($first['token']);

        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();
        app(TokenService::class)->revoke($server);

        $second = app(TokenService::class)->generateEnrollmentToken(null, 'Recovered device', 30);

        $response = $this->postJson('/api/sync/v1/enroll', $this->enrollmentPayload($second['token'], [
            'server_uuid' => $data['server_uuid'],
            'name' => 'Recovered Offline Server',
        ]))->assertCreated();

        $newToken = $response->json('data.server_token');

        $this->assertNotSame($data['server_token'], $newToken);
        $this->assertDatabaseCount('offline_servers', 1);
        $this->assertDatabaseHas('offline_server_enrollment_tokens', [
            'id' => $second['id'],
            'used_by_server_id' => $server->id,
        ]);

        $server->refresh();

        $this->assertSame(OfflineServer::STATUS_ACTIVE, $server->status);
        $this->assertNull($server->revoked_at);
        $this->assertSame('Recovered Offline Server', $server->name);
        $this->assertSame(hash('sha256', $newToken), $server->api_token_hash);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/heartbeat')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_server_token');

        $this->withHeader((string) config('sync.server_token_header'), $newToken)
            ->postJson('/api/sync/v1/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.server_uuid', $server->server_uuid);
    }

    public function test_an_active_server_cannot_consume_another_enrollment_token(): void
    {
        $first = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($first['token']);

        $second = app(TokenService::class)->generateEnrollmentToken();

        $this->postJson('/api/sync/v1/enroll', $this->enrollmentPayload($second['token'], [
            'server_uuid' => $data['server_uuid'],
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'server_already_enrolled');

        $this->assertNull(OfflineServerEnrollmentToken::query()->findOrFail($second['id'])->used_at);
        $this->assertDatabaseCount('offline_servers', 1);
    }

    public function test_a_server_can_enroll_with_a_region_scope(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $data = $this->enroll($issued['token'], [
            'scope_type' => 'region',
            'scope_code' => 'BARMM',
        ]);

        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();

        $this->assertDatabaseHas('offline_server_scopes', [
            'offline_server_id' => $server->id,
            'scope_type' => 'region',
            'location_code' => 'BARMM',
        ]);
    }

    public function test_unsupported_version_headers_return_426_for_authenticated_requests(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->withHeader((string) config('sync.protocol_header'), '99')
            ->postJson('/api/sync/v1/heartbeat')
            ->assertStatus(426)
            ->assertJsonPath('error.code', 'unsupported_sync_version');
    }
}
