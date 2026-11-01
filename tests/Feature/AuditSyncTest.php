<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OfflineServer;
use App\Models\SyncSetting;
use App\Models\User;
use App\Services\Sync\AuditSyncService;
use App\Services\Sync\SyncClient;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuditSyncTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * @return array<string, mixed>
     */
    private function enroll(): array
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $response = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Lanao del Sur Offline',
            'app_version' => '1.0.0',
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'province',
            'scope_code' => '1900702000',
        ]);

        $response->assertCreated();

        return $response->json('data');
    }

    public function test_the_central_server_stores_audit_events_from_an_offline_installation(): void
    {
        $data = $this->enroll();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/audit-events', [
                'events' => [[
                    'event_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                    'action' => 'beneficiary.created',
                    'entity_type' => 'beneficiary',
                    'entity_id' => 12,
                    'actor_uuid' => '11111111-2222-4333-8444-555555555555',
                    'actor_name' => 'Maria Dela Cruz',
                    'actor_username' => 'm_delacruz',
                    'actor_role' => 'encoder',
                    'occurred_at' => now()->subHour()->toIso8601String(),
                    'metadata' => ['barangay' => 'P.O. BOX 23'],
                    'ip' => '10.0.0.5',
                ]],
                'encoders' => [[
                    'encoder_uuid' => '11111111-2222-4333-8444-555555555555',
                    'username' => 'm_delacruz',
                    'name' => 'Maria Dela Cruz',
                    'role' => 'encoder',
                    'is_active' => true,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.accepted_events', 1);

        $log = AuditLog::query()->where('event_uuid', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')->firstOrFail();

        $this->assertSame('beneficiary.created', $log->action);
        $this->assertSame('Maria Dela Cruz', $log->actor_name);
        $this->assertSame('m_delacruz', $log->actor_username);
        $this->assertSame('encoder', $log->actor_role);
        $this->assertSame($data['server_uuid'], $log->origin_server_uuid);
        $this->assertNotNull($log->synced_at);
        $this->assertNull($log->user_id);

        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();

        $this->assertDatabaseHas('offline_server_encoders', [
            'offline_server_id' => $server->id,
            'encoder_uuid' => '11111111-2222-4333-8444-555555555555',
            'username' => 'm_delacruz',
            'name' => 'Maria Dela Cruz',
            'is_active' => true,
        ]);
    }

    public function test_repeated_delivery_of_the_same_event_does_not_duplicate_the_record(): void
    {
        $data = $this->enroll();

        $payload = [
            'events' => [[
                'event_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                'action' => 'beneficiary.created',
                'actor_username' => 'm_delacruz',
                'occurred_at' => now()->toIso8601String(),
            ]],
        ];

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/audit-events', $payload)
            ->assertOk();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/audit-events', $payload)
            ->assertOk();

        $this->assertSame(1, AuditLog::query()->where('event_uuid', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')->count());
    }

    public function test_an_expired_installation_cannot_push_audit_events(): void
    {
        $data = $this->enroll();

        OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail()
            ->forceFill(['authorization_expires_at' => now()->subMinute()])->save();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/audit-events', [
                'events' => [[
                    'event_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                    'action' => 'beneficiary.created',
                ]],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_expired');

        $this->assertSame(0, AuditLog::query()->where('event_uuid', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')->count());
    }

    public function test_an_unauthenticated_request_cannot_push_audit_events(): void
    {
        $this->postJson('/api/sync/v1/audit-events', ['events' => []])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_server_token');
    }

    public function test_the_local_audit_trail_is_marked_as_synchronized_after_a_successful_push(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $encoder = User::factory()->create([
            'username' => 'm_delacruz',
            'role' => User::ROLE_ENCODER,
        ]);

        $log = AuditLog::create([
            'event_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'action' => 'beneficiary.created',
            'actor_uuid' => $encoder->uuid,
            'actor_name' => $encoder->name,
            'actor_username' => $encoder->username,
            'actor_role' => $encoder->role,
            'origin_server_uuid' => '77777777-7777-4777-8777-777777777777',
            'occurred_at' => now(),
        ]);

        Http::fake([
            '*/api/sync/v1/audit-events' => Http::response([
                'data' => [
                    'accepted_events' => 1,
                    'accepted_event_uuids' => [$log->event_uuid],
                ],
            ]),
        ]);

        SyncSetting::set('central_url', 'https://central.example.test');
        SyncSetting::set('server_uuid', '77777777-7777-4777-8777-777777777777');
        SyncSetting::setEncrypted('server_token', 'a-valid-server-token');

        $result = app(AuditSyncService::class)->push(app(SyncClient::class));

        $this->assertSame(1, $result['accepted']);
        $this->assertNull($result['error']);
        $this->assertNotNull($log->fresh()->synced_at);

        Http::assertSent(function ($request) use ($encoder): bool {
            $body = $request->data();

            $this->assertSame('m_delacruz', $body['encoders'][0]['username']);
            $this->assertSame($encoder->name, $body['encoders'][0]['name']);
            $this->assertSame($encoder->name, $body['events'][0]['actor_name']);
            $this->assertArrayNotHasKey('password', $body['encoders'][0]);
            $this->assertArrayNotHasKey('email', $body['encoders'][0]);

            return true;
        });
    }

    public function test_the_encoder_registry_never_contains_credentials(): void
    {
        User::factory()->create([
            'username' => 'm_delacruz',
            'role' => User::ROLE_ENCODER,
            'email' => 'm_delacruz@example.test',
        ]);

        $registry = app(AuditSyncService::class)->registry();

        $this->assertCount(1, $registry);
        $this->assertSame(
            ['encoder_uuid', 'username', 'name', 'role', 'is_active'],
            array_keys($registry[0]),
        );
        $this->assertNotSame('', $registry[0]['name']);
    }

    public function test_the_registry_includes_the_manager_and_encoders_but_not_staff(): void
    {
        User::factory()->create(['username' => 'the_manager', 'role' => User::ROLE_MANAGER, 'email' => 'mgr@example.test']);
        User::factory()->create(['username' => 'an_encoder', 'role' => User::ROLE_ENCODER]);
        User::factory()->create(['username' => 'a_staff', 'role' => User::ROLE_STAFF]);

        $registry = app(AuditSyncService::class)->registry();

        $this->assertSame(['the_manager', 'an_encoder'], array_column($registry, 'username'));
        $this->assertSame([User::ROLE_MANAGER, User::ROLE_ENCODER], array_column($registry, 'role'));

        foreach ($registry as $entry) {
            $this->assertSame(['encoder_uuid', 'username', 'name', 'role', 'is_active'], array_keys($entry));
            $this->assertArrayNotHasKey('password', $entry);
            $this->assertArrayNotHasKey('email', $entry);
        }
    }

    public function test_pending_audit_events_are_counted_locally(): void
    {
        AuditLog::create([
            'event_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'action' => 'beneficiary.created',
            'occurred_at' => now(),
        ]);

        $this->assertSame(1, app(AuditSyncService::class)->pendingCount());
    }
}
