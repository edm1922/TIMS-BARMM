<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\OfflineServer;
use App\Models\OfflineServerEnrollmentToken;
use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Models\User;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineAuthorizationTest extends TestCase
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
            'name' => 'Lanao del Sur Offline',
            'app_version' => '1.0.0',
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'province',
            'scope_code' => '1900702000',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function enroll(string $token, array $overrides = []): array
    {
        $response = $this->postJson('/api/sync/v1/enroll', $this->enrollmentPayload($token, $overrides));
        $response->assertCreated();

        return $response->json('data');
    }

    private function server(string $serverUuid): OfflineServer
    {
        return OfflineServer::query()->where('server_uuid', $serverUuid)->firstOrFail();
    }

    public function test_enrollment_applies_the_authorization_period_to_the_server(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken(null, 'Field Laptop', 30);

        $data = $this->enroll($issued['token']);

        $server = $this->server($data['server_uuid']);

        $this->assertNotNull($server->authorized_at);
        $this->assertNotNull($server->authorization_expires_at);
        $this->assertSame('active', $server->authorizationStatus());
        $this->assertTrue($server->isAuthorized());
        $this->assertNotNull($data['authorization_expires_at']);
    }

    public function test_uploads_are_rejected_after_the_authorization_period_ends(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $server = $this->server($data['server_uuid']);
        $server->forceFill(['authorization_expires_at' => now()->subMinute()])->save();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/operations', [
                'operations' => [[
                    'operation_uuid' => '11111111-1111-4111-8111-111111111111',
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => '22222222-2222-4222-8222-222222222222',
                    'operation' => 'created',
                    'payload' => ['first_name' => 'TEST'],
                ]],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_expired');

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_reads_are_rejected_after_the_authorization_period_ends(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $server = $this->server($data['server_uuid']);
        $server->forceFill(['authorization_expires_at' => now()->subMinute()])->save();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->getJson('/api/sync/v1/changes')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_expired');
    }

    public function test_health_endpoints_remain_available_after_expiry(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $server = $this->server($data['server_uuid']);
        $server->forceFill(['authorization_expires_at' => now()->subMinute()])->save();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->getJson('/api/sync/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_uploads_are_accepted_while_the_authorization_is_valid(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/operations', [
                'operations' => [[
                    'operation_uuid' => '33333333-3333-4333-8333-333333333333',
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => '44444444-4444-4444-8444-444444444444',
                    'operation' => 'created',
                    'payload' => [
                        'first_name' => 'TEST',
                        'last_name' => 'BENEFICIARY',
                        'location_code' => '1900702000',
                    ],
                ]],
            ])
            ->assertOk();
    }

    public function test_reauthorization_token_restores_the_authorization_period(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $server = $this->server($data['server_uuid']);
        $server->forceFill(['authorization_expires_at' => now()->subMinute()])->save();

        $reauthorization = app(TokenService::class)->generateReauthorizationToken($server, $this->admin()->id, 60);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/reauthorize', [
                'reauthorization_token' => $reauthorization['token'],
            ])
            ->assertOk()
            ->assertJsonPath('data.authorization_status', 'active');

        $server->refresh();
        $this->assertTrue($server->isAuthorized());
        $this->assertTrue($server->authorization_expires_at->isFuture());
    }

    public function test_a_reauthorization_token_cannot_be_used_twice(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $server = $this->server($data['server_uuid']);
        $server->forceFill(['authorization_expires_at' => now()->subMinute()])->save();

        $reauthorization = app(TokenService::class)->generateReauthorizationToken($server);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/reauthorize', ['reauthorization_token' => $reauthorization['token']])
            ->assertOk();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/reauthorize', ['reauthorization_token' => $reauthorization['token']])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_reauthorization_token');
    }

    public function test_a_reauthorization_token_cannot_be_used_by_another_installation(): void
    {
        $first = app(TokenService::class)->generateEnrollmentToken();
        $firstData = $this->enroll($first['token']);

        $second = app(TokenService::class)->generateEnrollmentToken();
        $secondData = $this->enroll($second['token']);

        $reauthorization = app(TokenService::class)->generateReauthorizationToken($this->server($firstData['server_uuid']));

        $this->withHeader((string) config('sync.server_token_header'), $secondData['server_token'])
            ->postJson('/api/sync/v1/reauthorize', ['reauthorization_token' => $reauthorization['token']])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_reauthorization_token');
    }

    public function test_an_expired_reauthorization_token_is_rejected(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $server = $this->server($data['server_uuid']);
        $server->forceFill(['authorization_expires_at' => now()->subMinute()])->save();

        $reauthorization = app(TokenService::class)->generateReauthorizationToken($server);

        OfflineServerEnrollmentToken::query()->findOrFail($reauthorization['id'])
            ->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/reauthorize', ['reauthorization_token' => $reauthorization['token']])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_reauthorization_token');
    }

    public function test_an_enrollment_token_cannot_be_used_for_reauthorization(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/reauthorize', ['reauthorization_token' => $issued['token']])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_reauthorization_token');
    }

    public function test_reauthorization_preserves_pending_operations_and_audit_history(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $server = $this->server($data['server_uuid']);

        SyncOperation::create([
            'operation_uuid' => '55555555-5555-4555-8555-555555555555',
            'entity_type' => 'beneficiary',
            'entity_uuid' => '66666666-6666-4666-8666-666666666666',
            'operation' => 'created',
            'origin_server_uuid' => $server->server_uuid,
            'payload' => ['first_name' => 'PENDING'],
            'status' => SyncOperation::STATUS_PENDING,
            'available_at' => now(),
        ]);

        $server->forceFill(['authorization_expires_at' => now()->subMinute()])->save();

        $reauthorization = app(TokenService::class)->generateReauthorizationToken($server);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/reauthorize', ['reauthorization_token' => $reauthorization['token']])
            ->assertOk();

        $this->assertSame(1, SyncOperation::query()->pending()->count());
    }

    public function test_admin_can_issue_a_reauthorization_token_from_the_server_page(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();
        $data = $this->enroll($issued['token']);

        $server = $this->server($data['server_uuid']);

        $this->actingAs($this->admin())
            ->post(route('sync-servers.reauthorization-token', $server), ['authorization_days' => 30])
            ->assertRedirect(route('sync-servers.show', $server))
            ->assertSessionHas('reauthorization_token');

        $this->assertDatabaseHas('offline_server_enrollment_tokens', [
            'token_type' => OfflineServerEnrollmentToken::TYPE_REAUTHORIZATION,
            'target_offline_server_id' => $server->id,
        ]);
    }

    public function test_non_admins_cannot_issue_reauthorization_tokens(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);

        $this->actingAs($staff)
            ->post(route('sync-servers.reauthorization-token', OfflineServer::factory()->create()), [
                'authorization_days' => 30,
            ])
            ->assertForbidden();
    }

    public function test_offline_installation_blocks_protected_writes_after_expiry(): void
    {
        config()->set('sync.instance_mode', 'offline');

        SyncSetting::set('server_uuid', '77777777-7777-4777-8777-777777777777');
        SyncSetting::setEncrypted('server_token', 'a-server-token');
        SyncSetting::set('authorization_expires_at', now()->subDay()->toIso8601String());
        SyncSetting::flushCache();

        $user = $this->admin();
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Authorization Batch']);

        $this->actingAs($user)
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_offline_installation_allows_protected_writes_while_authorized(): void
    {
        config()->set('sync.instance_mode', 'offline');

        SyncSetting::set('server_uuid', '77777777-7777-4777-8777-777777777777');
        SyncSetting::setEncrypted('server_token', 'a-server-token');
        SyncSetting::set('authorization_expires_at', now()->addDay()->toIso8601String());
        SyncSetting::flushCache();

        $user = $this->admin();
        $location = $this->seededLocation();
        $batch = $this->seededBatch(['name' => 'Authorization Batch']);

        $this->actingAs($user)
            ->post('/batches/'.$batch->id.'/beneficiaries', $this->beneficiaryPayload($location))
            ->assertRedirect(route('beneficiary-batches.show', $batch));

        $this->assertSame(1, Beneficiary::query()->count());
    }

    public function test_offline_installation_keeps_reads_available_after_expiry(): void
    {
        config()->set('sync.instance_mode', 'offline');

        SyncSetting::set('server_uuid', '77777777-7777-4777-8777-777777777777');
        SyncSetting::setEncrypted('server_token', 'a-server-token');
        SyncSetting::set('authorization_expires_at', now()->subDay()->toIso8601String());
        SyncSetting::flushCache();

        Beneficiary::factory()->create();

        $this->actingAs($this->admin())
            ->get('/beneficiaries')
            ->assertOk();
    }

    public function test_login_session_does_not_extend_the_authorization_period(): void
    {
        config()->set('sync.instance_mode', 'offline');

        SyncSetting::set('server_uuid', '77777777-7777-4777-8777-777777777777');
        SyncSetting::setEncrypted('server_token', 'a-server-token');
        $expiresAt = now()->addDay();
        SyncSetting::set('authorization_expires_at', $expiresAt->toIso8601String());
        SyncSetting::flushCache();

        $user = $this->admin();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertSame(
            $expiresAt->toIso8601String(),
            SyncSetting::get('authorization_expires_at'),
        );
    }
}
