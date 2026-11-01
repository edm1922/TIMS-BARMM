<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncChange;
use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Models\User;
use App\Services\Sync\SyncOperationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SyncSetting::flushCache();
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_MANAGER]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function configureOffline(): void
    {
        config([
            'sync.instance_mode' => 'offline',
            'sync.local_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'sync.bootstrap_url' => 'https://central.test',
        ]);

        SyncSetting::flushCache();
    }

    private function configureEnrolled(string $centralUrl = 'https://central.test'): void
    {
        SyncSetting::set('central_url', $centralUrl);
        SyncSetting::set('server_uuid', '22222222-2222-4222-8222-222222222222');
        SyncSetting::setEncrypted('server_token', 'tims_sync_test_token');
        SyncSetting::flushCache();
    }

    public function test_offline_writes_are_queued_with_origin_and_base_revision(): void
    {
        $this->configureOffline();

        $beneficiary = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'notes' => 'Created locally.',
        ]);

        $created = SyncOperation::query()->firstOrFail();

        $this->assertSame('pending', $created->status);
        $this->assertSame('beneficiary', $created->entity_type);
        $this->assertSame($beneficiary->global_uuid, $created->entity_uuid);
        $this->assertSame('created', $created->operation);
        $this->assertSame(0, $created->base_revision);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $created->origin_server_uuid);
        $this->assertSame('Created locally.', $created->payload['notes']);

        $beneficiary->update(['notes' => 'Edited locally.']);

        $updated = SyncOperation::query()->latest('id')->firstOrFail();

        $this->assertSame('updated', $updated->operation);
        $this->assertSame(1, $updated->base_revision);
        $this->assertSame(2, $beneficiary->fresh()->revision);
        $this->assertContains('notes', $updated->changed_fields);
    }

    public function test_snapshot_imports_do_not_create_local_operations(): void
    {
        $this->configureOffline();

        $created = SyncOperationRecorder::withoutRecording(
            fn (): Beneficiary => Beneficiary::factory()->create(),
        );

        $this->assertSame(0, SyncOperation::query()->count());
        $this->assertSame(0, SyncChange::query()->count());
        $this->assertSame(1, $created->revision);
    }

    public function test_archive_unarchive_delete_and_bulk_removal_are_queued(): void
    {
        $this->configureOffline();

        $batch = BeneficiaryBatch::factory()->create(['name' => 'Queue Batch']);
        $beneficiary = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'beneficiary_batch_id' => $batch->id,
        ]);

        SyncOperation::query()->delete();

        $beneficiary->archive('Queue test');
        $archived = SyncOperation::query()->latest('id')->firstOrFail();

        $this->assertSame('archived', $archived->operation);
        $this->assertSame(1, $archived->base_revision);

        $beneficiary->unarchive();
        $unarchived = SyncOperation::query()->latest('id')->firstOrFail();

        $this->assertSame('unarchived', $unarchived->operation);

        $batch->delete();
        $deleted = SyncOperation::query()->latest('id')->firstOrFail();

        $this->assertSame('beneficiary_batch', $deleted->entity_type);
        $this->assertSame('deleted', $deleted->operation);

        $otherBatch = BeneficiaryBatch::factory()->create(['name' => 'Bulk Removal Batch']);
        $member = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'beneficiary_batch_id' => $otherBatch->id,
        ]);

        SyncOperation::query()->delete();

        $this->actingAs($this->admin())
            ->delete(route('beneficiary-batches.members.destroy', $otherBatch), ['ids' => [$member->id]])
            ->assertRedirect(route('beneficiary-batches.show', $otherBatch));

        $removed = SyncOperation::query()->latest('id')->firstOrFail();

        $this->assertSame('beneficiary', $removed->entity_type);
        $this->assertSame('updated', $removed->operation);
        $this->assertContains('beneficiary_batch_id', $removed->changed_fields);
        $this->assertNull($member->fresh()->beneficiary_batch_id);
    }

    public function test_sync_settings_store_server_tokens_encrypted(): void
    {
        SyncSetting::setEncrypted('server_token', 'super-secret-token');

        $this->assertSame('super-secret-token', SyncSetting::getEncrypted('server_token'));
        $this->assertNotSame('super-secret-token', SyncSetting::get('server_token'));
    }

    public function test_admin_can_enroll_with_a_one_time_token(): void
    {
        $this->configureOffline();

        $serverUuid = '33333333-3333-4333-8333-333333333333';

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => [
                    'protocol_version' => 1,
                    'minimum_protocol_version' => 1,
                    'schema_version' => 1,
                    'minimum_schema_version' => 1,
                ],
            ]),
            'https://central.test/api/sync/v1/enroll' => Http::response([
                'data' => [
                    'server_uuid' => $serverUuid,
                    'server_token' => 'tims_sync_enrolled_token',
                    'central_server_uuid' => '44444444-4444-4444-8444-444444444444',
                    'enrolled_at' => '2026-10-23T12:00:00+08:00',
                ],
            ], 201),
        ]);

        $this->actingAs($this->manager())
            ->post('/sync/setup/enroll', [
                'enrollment_token' => 'one-time-token',
                'server_name' => 'Offline Test Server',
            ])
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status');

        $this->assertSame('https://central.test', SyncSetting::get('central_url'));
        $this->assertSame($serverUuid, SyncSetting::get('server_uuid'));
        $this->assertSame($serverUuid, SyncSetting::get('local_server_uuid'));
        $this->assertSame('region', SyncSetting::get('scope_type'));
        $this->assertSame('BARMM', SyncSetting::get('scope_code'));
        $this->assertSame('tims_sync_enrolled_token', SyncSetting::getEncrypted('server_token'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://central.test/api/sync/v1/enroll'
            && $request['enrollment_token'] === 'one-time-token'
            && $request['name'] === 'Offline Test Server'
            && $request['scope_type'] === 'region'
            && $request['scope_code'] === 'BARMM');
    }

    public function test_the_enrollment_card_only_asks_for_a_server_name_and_token(): void
    {
        $this->configureOffline();

        $this->actingAs($this->manager())
            ->get('/sync/setup')
            ->assertOk()
            ->assertSee('Server name')
            ->assertSee('Enrollment token')
            ->assertSee('https://central.test')
            ->assertSee('Coverage: entire BARMM region')
            ->assertDontSee('name="central_url"', false)
            ->assertDontSee('name="scope_type"', false)
            ->assertDontSee('name="scope_code"', false)
            ->assertDontSee('name="province"', false)
            ->assertDontSee('name="city_municipality"', false)
            ->assertDontSee('name="barangay"', false)
            ->assertDontSee('PSGC');
    }

    public function test_enrollment_without_a_provisioned_central_server_is_refused(): void
    {
        $this->configureOffline();

        config(['sync.bootstrap_url' => null]);
        SyncSetting::flushCache();

        Http::fake();

        $this->actingAs($this->manager())
            ->post('/sync/setup/enroll', [
                'enrollment_token' => 'one-time-token',
                'server_name' => 'Offline Test Server',
            ])
            ->assertSessionHasErrors('enrollment_token');

        Http::assertNothingSent();
        $this->assertNull(SyncSetting::get('central_url'));
        $this->assertNull(SyncSetting::get('server_uuid'));
    }

    public function test_the_enrollment_card_warns_when_the_central_address_is_loopback(): void
    {
        $this->configureOffline();
        config(['sync.bootstrap_url' => 'http://localhost/TIMS-BARMM/public']);
        SyncSetting::flushCache();

        $this->actingAs($this->manager())
            ->get('/sync/setup')
            ->assertOk()
            ->assertSee('This is a loopback address');
    }

    public function test_the_compatibility_failure_names_the_address_and_the_http_status(): void
    {
        $this->configureOffline();

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response('Not Found', 404),
        ]);

        $this->actingAs($this->manager())
            ->post('/sync/setup/enroll', [
                'enrollment_token' => 'one-time-token',
                'server_name' => 'Offline Test Server',
            ])
            ->assertSessionHasErrors('enrollment_token');

        $message = (string) session('errors')->first('enrollment_token');

        $this->assertStringContainsString('https://central.test', $message);
        $this->assertStringContainsString('HTTP 404', $message);
        $this->assertNull(SyncSetting::get('server_uuid'));
    }

    public function test_the_enrollment_page_offers_recovery_for_an_enrolled_installation(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        $this->actingAs($this->manager())
            ->get('/sync/setup')
            ->assertOk()
            ->assertSee('Recover this installation')
            ->assertSee('New enrollment token')
            ->assertSee('name="server_name"', false)
            ->assertSee('name="enrollment_token"', false);
    }

    public function test_re_enrollment_reuses_the_stored_server_uuid_and_scope(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        SyncSetting::set('scope_type', 'province');
        SyncSetting::set('scope_code', '1900702000');
        SyncSetting::set('server_name', 'Lanao del Sur Offline');
        SyncSetting::flushCache();

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/enroll' => Http::response([
                'data' => [
                    'server_uuid' => '22222222-2222-4222-8222-222222222222',
                    'server_token' => 'tims_sync_recovered_token',
                    'central_server_uuid' => '44444444-4444-4444-8444-444444444444',
                    'enrolled_at' => '2026-10-26T12:00:00+08:00',
                    'authorized_at' => '2026-10-26T12:00:00+08:00',
                    'authorization_expires_at' => '2027-01-24T12:00:00+08:00',
                    'authorization_status' => 'active',
                ],
            ], 201),
        ]);

        $this->actingAs($this->manager())
            ->post('/sync/setup/enroll', [
                'enrollment_token' => 'recovery-token',
                'server_name' => 'Lanao del Sur Offline',
            ])
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('22222222-2222-4222-8222-222222222222', SyncSetting::get('server_uuid'));
        $this->assertSame('tims_sync_recovered_token', SyncSetting::getEncrypted('server_token'));
        $this->assertSame('province', SyncSetting::get('scope_type'));
        $this->assertSame('1900702000', SyncSetting::get('scope_code'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://central.test/api/sync/v1/enroll'
            && $request['enrollment_token'] === 'recovery-token'
            && $request['server_uuid'] === '22222222-2222-4222-8222-222222222222'
            && $request['scope_type'] === 'province'
            && $request['scope_code'] === '1900702000');
    }

    public function test_enrollment_failure_does_not_store_partial_settings(): void
    {
        $this->configureOffline();

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/enroll' => Http::response([
                'error' => ['message' => 'Invalid enrollment token.'],
            ], 422),
        ]);

        $this->actingAs($this->manager())
            ->post('/sync/setup/enroll', [
                'enrollment_token' => 'bad-token',
                'server_name' => 'Offline Test Server',
            ])
            ->assertSessionHasErrors('enrollment_token');

        $this->assertNull(SyncSetting::get('central_url'));
        $this->assertNull(SyncSetting::get('server_uuid'));
        $this->assertNull(SyncSetting::getEncrypted('server_token'));
    }

    public function test_snapshot_download_imports_reference_and_beneficiary_pages(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        Http::fake([
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'snapshot-uuid-1',
                    'status' => 'ready',
                    'revision_to' => 42,
                ],
            ], 201),
            'https://central.test/api/sync/v1/snapshot/snapshot-uuid-1/reference' => Http::response([
                'data' => [
                    'locations' => [[
                        'psgc_code' => '1900702001',
                        'barangay' => 'TEST BARANGAY',
                        'city_municipality' => 'TEST CITY',
                        'province' => 'TEST PROVINCE',
                        'full_address' => 'TEST BARANGAY, TEST CITY, TEST PROVINCE',
                        'is_city' => false,
                    ]],
                    'batches' => [[
                        'global_uuid' => '55555555-5555-4555-8555-555555555555',
                        'origin_server_uuid' => '44444444-4444-4444-8444-444444444444',
                        'name' => 'Central Batch',
                        'description' => 'Imported batch',
                        'status' => 'open',
                        'revision' => 3,
                        'deleted_at' => null,
                    ]],
                    'trainings' => [[
                        'category' => 'SKILLS',
                        'name' => 'Central Training',
                    ]],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/snapshot-uuid-1/beneficiaries*' => Http::sequence()
                ->push([
                    'data' => [[
                        'global_uuid' => '66666666-6666-4666-8666-666666666666',
                        'origin_server_uuid' => '44444444-4444-4444-8444-444444444444',
                        'revision' => 5,
                        'beneficiary_code' => 'CENTRAL-0001',
                        'first_name' => 'CENTRAL',
                        'last_name' => 'PERSON',
                        'birthdate' => '1990-01-01',
                        'location_code' => '1900702001',
                        'beneficiary_batch_uuid' => '55555555-5555-4555-8555-555555555555',
                        'duplicate_status' => 'none',
                    ]],
                    'meta' => [
                        'next_cursor' => 10,
                        'has_more' => true,
                        'count' => 1,
                    ],
                ])
                ->push([
                    'data' => [[
                        'global_uuid' => '77777777-7777-4777-8777-777777777777',
                        'origin_server_uuid' => '44444444-4444-4444-8444-444444444444',
                        'revision' => 6,
                        'beneficiary_code' => 'CENTRAL-0002',
                        'first_name' => 'SECOND',
                        'last_name' => 'PERSON',
                        'location_code' => '1900702001',
                        'beneficiary_batch_uuid' => null,
                        'duplicate_status' => 'none',
                    ]],
                    'meta' => [
                        'next_cursor' => 20,
                        'has_more' => false,
                        'count' => 1,
                    ],
                ]),
        ]);

        $this->actingAs($this->manager())
            ->post('/sync/setup/download')
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('locations', ['psgc_code' => '1900702001']);
        $this->assertDatabaseHas('trainings', ['category' => 'SKILLS', 'name' => 'Central Training']);
        $this->assertDatabaseHas('beneficiary_batches', [
            'global_uuid' => '55555555-5555-4555-8555-555555555555',
            'name' => 'Central Batch',
            'revision' => 3,
        ]);
        $this->assertDatabaseHas('beneficiaries', [
            'global_uuid' => '66666666-6666-4666-8666-666666666666',
            'beneficiary_code' => 'CENTRAL-0001',
            'revision' => 5,
        ]);
        $this->assertDatabaseHas('beneficiaries', [
            'global_uuid' => '77777777-7777-4777-8777-777777777777',
            'beneficiary_code' => 'CENTRAL-0002',
        ]);

        $batch = BeneficiaryBatch::query()->where('name', 'Central Batch')->firstOrFail();
        $first = Beneficiary::query()->where('beneficiary_code', 'CENTRAL-0001')->firstOrFail();

        $this->assertSame($batch->id, $first->beneficiary_batch_id);
        $this->assertSame(0, SyncOperation::query()->count());
        $this->assertSame(0, SyncChange::query()->count());
        $this->assertSame('snapshot-uuid-1', SyncSetting::get('last_snapshot_uuid'));
        $this->assertSame('42', SyncSetting::get('last_snapshot_revision'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/snapshot/snapshot-uuid-1/beneficiaries')
            && $request->hasHeader('X-TIMS-Server-Token', 'tims_sync_test_token'));
    }

    public function test_interrupted_snapshot_download_resumes_from_the_saved_cursor(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        SyncSetting::set('snapshot_in_progress', json_encode([
            'snapshot_uuid' => 'resume-snapshot',
            'revision_to' => 42,
            'expected_beneficiaries' => 2,
            'cursor' => 10,
            'beneficiaries' => 1,
            'locations' => 1,
            'trainings' => 1,
            'pages' => 1,
            'started_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        SyncSetting::flushCache();

        Http::fake([
            'https://central.test/api/sync/v1/snapshot/resume-snapshot/reference' => Http::response([
                'data' => [
                    'locations' => [[
                        'psgc_code' => '1900702001',
                        'barangay' => 'TEST BARANGAY',
                        'city_municipality' => 'TEST CITY',
                        'province' => 'TEST PROVINCE',
                        'full_address' => 'TEST BARANGAY, TEST CITY, TEST PROVINCE',
                        'is_city' => false,
                    ]],
                    'batches' => [],
                    'trainings' => [],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/resume-snapshot/beneficiaries*' => Http::response([
                'data' => [[
                    'global_uuid' => '88888888-8888-4888-8888-888888888888',
                    'origin_server_uuid' => '44444444-4444-4444-8444-444444444444',
                    'revision' => 7,
                    'beneficiary_code' => 'RESUMED-0002',
                    'first_name' => 'RESUMED',
                    'last_name' => 'PERSON',
                    'location_code' => '1900702001',
                    'duplicate_status' => 'none',
                ]],
                'meta' => ['next_cursor' => 20, 'has_more' => false, 'count' => 1],
            ]),
        ]);

        $this->actingAs($this->manager())
            ->post('/sync/setup/download')
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('beneficiaries', ['beneficiary_code' => 'RESUMED-0002']);
        $this->assertSame('resume-snapshot', SyncSetting::get('last_snapshot_uuid'));
        $this->assertSame('2', SyncSetting::get('last_snapshot_beneficiaries'));
        $this->assertNull(SyncSetting::get('snapshot_in_progress'));

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://central.test/api/sync/v1/snapshot');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'cursor=10'));
    }

    public function test_staff_cannot_access_local_sync_setup(): void
    {
        $this->configureOffline();

        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);

        $this->actingAs($staff)->get('/sync/setup')->assertForbidden();
    }

    public function test_retry_requeues_only_operations_rejected_by_the_former_scope_rule(): void
    {
        $this->configureOffline();

        $scopeRejected = $this->operation([
            'status' => SyncOperation::STATUS_REJECTED,
            'last_error_code' => 'out_of_scope',
            'last_error' => 'The beneficiary location is outside this server scope.',
        ]);
        $legacyRejected = $this->operation([
            'status' => SyncOperation::STATUS_REJECTED,
            'last_error_code' => null,
            'last_error' => 'The beneficiary location is outside this server scope.',
        ]);
        $invalidRejected = $this->operation([
            'status' => SyncOperation::STATUS_REJECTED,
            'last_error_code' => 'validation_failed',
            'last_error' => 'First name and last name are required.',
        ]);
        $synced = $this->operation(['status' => SyncOperation::STATUS_SYNCED]);

        $this->actingAs($this->manager())
            ->post(route('sync.local.retry-rejected'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status');

        $this->assertSame(SyncOperation::STATUS_PENDING, $scopeRejected->fresh()->status);
        $this->assertSame(0, $scopeRejected->fresh()->attempts);
        $this->assertSame(SyncOperation::STATUS_PENDING, $legacyRejected->fresh()->status);
        $this->assertSame(SyncOperation::STATUS_REJECTED, $invalidRejected->fresh()->status);
        $this->assertSame(SyncOperation::STATUS_SYNCED, $synced->fresh()->status);
    }

    public function test_retry_reports_when_nothing_is_eligible(): void
    {
        $this->configureOffline();

        $this->operation(['status' => SyncOperation::STATUS_SYNCED]);

        $this->actingAs($this->manager())
            ->post(route('sync.local.retry-rejected'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status', 'No rejected operations are eligible for retry.');
    }

    public function test_encoder_cannot_retry_rejected_operations(): void
    {
        $this->configureOffline();

        $encoder = User::factory()->create(['role' => User::ROLE_ENCODER]);

        $this->actingAs($encoder)
            ->post(route('sync.local.retry-rejected'))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function operation(array $overrides = []): SyncOperation
    {
        return SyncOperation::create(array_merge([
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'base_revision' => 0,
            'payload' => [
                'first_name' => 'QUEUED',
                'last_name' => 'PERSON',
                'location_code' => '1900702001',
            ],
            'status' => SyncOperation::STATUS_REJECTED,
        ], $overrides));
    }
}
