<?php

namespace Tests\Feature;

use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SetupOperationsRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_setup_page_loads_the_twenty_newest_local_operations(): void
    {
        config(['sync.instance_mode' => 'offline']);
        SyncSetting::flushCache();

        $created = [];

        for ($i = 1; $i <= 25; $i++) {
            $created[] = SyncOperation::query()->create([
                'entity_type' => 'beneficiary',
                'entity_uuid' => (string) Str::uuid(),
                'operation' => 'created',
                'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
                'local_id' => $i,
                'base_revision' => 0,
                'payload' => [],
                'changed_fields' => [],
                'status' => SyncOperation::STATUS_PENDING,
                'available_at' => now(),
            ]);
        }

        $admin = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $response = $this->actingAs($admin)->get(route('sync.local.index'));
        $response->assertOk();

        $operations = $response->viewData('operations');
        $this->assertCount(20, $operations);
        $this->assertSame($created[24]->id, $operations->first()->id);
        $this->assertSame($created[5]->id, $operations->last()->id);
    }

    public function test_the_setup_page_formats_timestamps_in_a_readable_local_format(): void
    {
        config(['sync.instance_mode' => 'offline']);
        SyncSetting::set('central_url', 'https://central.test');
        SyncSetting::set('server_uuid', '22222222-2222-4222-8222-222222222222');
        SyncSetting::setEncrypted('server_token', 'token');
        SyncSetting::set('last_sync_at', '2026-11-11T15:59:36+08:00');
        SyncSetting::flushCache();

        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($manager)->get(route('sync.local.index'))
            ->assertOk()
            ->assertSee('November 11, 2026 3:59 PM')
            ->assertDontSee('2026-11-11T15:59:36+08:00');
    }

    public function test_the_setup_page_offers_a_refresh_control_and_bfcache_reload(): void
    {
        config(['sync.instance_mode' => 'offline']);
        SyncSetting::flushCache();

        $admin = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($admin)->get(route('sync.local.index'))
            ->assertOk()
            ->assertSee('Refresh operations')
            ->assertSee('pageshow', false);
    }
}
