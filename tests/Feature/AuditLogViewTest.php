<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\OfflineServer;
use App\Models\OfflineServerEncoder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_metadata_is_shown_as_readable_details_instead_of_raw_json(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.unassigned',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 3,
            'metadata' => [
                'batch_name' => 'DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)',
                'count' => 1,
                'beneficiary_ids' => [3617],
            ],
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();

        // Readable labels and values, fully visible.
        $response->assertSee('Batch Name:');
        $response->assertSee('DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)');
        $response->assertSee('Beneficiary IDs:');
        $response->assertSee('3617');

        // No raw JSON dump and no truncating title tooltip.
        $response->assertDontSee('{"batch_name"', false);
        $response->assertDontSee('text-truncate', false);
    }

    public function test_nested_and_boolean_metadata_values_are_readable(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.deleted',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 4,
            'metadata' => [
                'name' => 'Batch 1',
                'deleted_with_batch' => true,
                'outcomes' => ['applied' => 2, 'skipped' => 1],
            ],
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();

        $response->assertSee('Deleted with batch:');
        $response->assertSee('Yes');
        $response->assertSee('Applied:');
        $response->assertSee('Skipped:');
    }

    public function test_long_lists_can_be_expanded_in_full(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.deleted',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 5,
            'metadata' => [
                'beneficiary_ids' => range(3601, 3620),
            ],
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();

        $response->assertSee('20 items');
        $response->assertSee('3601');
        $response->assertSee('3620');
    }

    public function test_the_ids_of_still_active_beneficiaries_are_shown_by_name(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $beneficiary = Beneficiary::factory()->create([
            'first_name' => 'JUAN',
            'middle_name' => 'SANTOS',
            'last_name' => 'DELA CRUZ',
            'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-00001',
        ]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.unassigned',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 3,
            'metadata' => [
                'batch_name' => 'DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)',
                'count' => 1,
                'beneficiary_ids' => [$beneficiary->id],
            ],
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();

        $response->assertSee('Beneficiaries:');
        $response->assertSee('JUAN SANTOS DELA CRUZ');
        $response->assertDontSee('Beneficiary IDs:');
    }

    public function test_a_deleted_beneficiary_is_named_from_their_deletion_entry(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now()->subMinute(),
            'action' => 'beneficiary.deleted',
            'entity_type' => 'beneficiary',
            'entity_id' => 3617,
            'metadata' => [
                'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-00002',
                'full_name' => 'MARIA LOPEZ SANTOS',
                'deleted_with_batch' => true,
            ],
        ]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.deleted',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 5,
            'metadata' => [
                'name' => 'DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)',
                'deleted_beneficiary_count' => 1,
                'beneficiary_ids' => [3617],
            ],
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();

        $response->assertSee('Beneficiaries:');
        $response->assertSee('MARIA LOPEZ SANTOS');
    }

    public function test_an_unknown_beneficiary_keeps_their_id(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.deleted',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 6,
            'metadata' => [
                'beneficiary_ids' => [3617],
            ],
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();

        $response->assertSee('Beneficiary IDs:');
        $response->assertSee('3617');
    }

    public function test_an_id_from_another_installation_is_named_through_its_recorded_code(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $beneficiary = Beneficiary::factory()->create([
            'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-01284-043E',
            'first_name' => 'ELMIN',
            'middle_name' => 'GUMAGANA',
            'last_name' => 'LASAM',
        ]);

        $device = '043ebd23-daeb-4b2e-aeec-e371f5c9e565';

        // The device's own entry records the code for its local id 3617.
        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => null,
            'origin_server_uuid' => $device,
            'occurred_at' => now()->subMinute(),
            'action' => 'beneficiary.created',
            'entity_type' => 'beneficiary',
            'entity_id' => 3617,
            'metadata' => ['beneficiary_code' => $beneficiary->beneficiary_code],
        ]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => null,
            'origin_server_uuid' => $device,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.assigned',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 23,
            'metadata' => [
                'batch_name' => 'DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)',
                'count' => 1,
                'beneficiary_ids' => [3617],
            ],
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();

        $response->assertSee('Beneficiaries:');
        $response->assertSee('ELMIN GUMAGANA LASAM');
        $response->assertDontSee('Beneficiary IDs:');
    }

    public function test_the_action_filter_offers_readable_grouped_options(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach (['auth.login', 'beneficiary.created', 'beneficiary.created', 'beneficiary.deleted'] as $action) {
            AuditLog::create([
                'event_uuid' => (string) Str::uuid(),
                'user_id' => $admin->id,
                'occurred_at' => now(),
                'action' => $action,
            ]);
        }

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();

        // Grouped by the part of the system, with readable labels and counts.
        $response->assertSee('<optgroup label="Sign-in activity">', false);
        $response->assertSee('<optgroup label="Beneficiaries">', false);
        $response->assertSee('Signed in (1)');
        $response->assertSee('Registered a beneficiary (2)');
        $response->assertSee('Deleted a beneficiary (1)');

        // The raw technical keys are no longer what the operator reads.
        $response->assertDontSee('beneficiary.created (', false);
    }

    public function test_the_action_filter_names_a_row_by_its_readable_label(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.assigned',
        ]);

        $this->actingAs($admin)->get('/audit')
            ->assertOk()
            ->assertSee('Assigned a beneficiary to a batch');
    }

    public function test_the_action_filter_combines_an_action_that_was_renamed(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach (['enroll_server.package_generated', 'offline_installation.package_generated'] as $action) {
            AuditLog::create([
                'event_uuid' => (string) Str::uuid(),
                'user_id' => $admin->id,
                'occurred_at' => now(),
                'action' => $action,
            ]);
        }

        $response = $this->actingAs($admin)->get('/audit?action=installation.package.generated');

        $response->assertOk();
        $response->assertSee('Generated an installation package (2)');
        $response->assertSee('2 entries');
        $response->assertDontSee('No audit entries match these filters.');
    }

    public function test_an_action_missing_from_the_catalog_is_still_listed(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary.future_action',
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();
        $response->assertSee('<optgroup label="Other">', false);
        $response->assertSee('Beneficiary Future Action');
    }

    public function test_a_synced_entry_names_the_actor_instead_of_their_username(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => null,
            'actor_uuid' => (string) Str::uuid(),
            'actor_name' => 'TALIB P. CAMID II',
            'actor_username' => 'tpc2',
            'actor_role' => 'encoder',
            'origin_server_uuid' => (string) Str::uuid(),
            'occurred_at' => now(),
            'action' => 'beneficiary.created',
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();
        $response->assertSee('TALIB P. CAMID II');
        $response->assertSee('Encoder');
    }

    public function test_an_older_synced_entry_is_named_from_the_reported_account_registry(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $server = OfflineServer::factory()->create();

        OfflineServerEncoder::create([
            'offline_server_id' => $server->id,
            'encoder_uuid' => '74afe040-f21d-478f-b845-b89a5aaedc64',
            'username' => 'tpc1',
            'name' => 'DATU ALI K. MACABAW',
            'role' => 'encoder',
            'is_active' => true,
            'reported_at' => now(),
        ]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => null,
            'actor_uuid' => '74afe040-f21d-478f-b845-b89a5aaedc64',
            'actor_username' => 'tpc1',
            'actor_role' => 'encoder',
            'origin_server_uuid' => $server->server_uuid,
            'occurred_at' => now(),
            'action' => 'beneficiary.created',
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk();
        $response->assertSee('DATU ALI K. MACABAW');
    }

    public function test_an_entry_with_an_unknown_actor_keeps_the_username(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'LOCAL ADMINISTRATOR ACCOUNT']);

        // The record id belongs to the other installation, so it must never be
        // matched to a local account that happens to share the same id.
        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'actor_uuid' => null,
            'actor_username' => 'ghost_encoder',
            'actor_role' => 'encoder',
            'origin_server_uuid' => (string) Str::uuid(),
            'occurred_at' => now(),
            'action' => 'beneficiary.created',
        ]);

        $response = $this->actingAs($admin)->get('/audit');

        $response->assertOk()->assertSee('ghost_encoder');

        // The local account's name must not appear as the actor of that row.
        $tbody = substr($response->getContent(), strpos($response->getContent(), '<tbody>'));
        $this->assertStringNotContainsString('LOCAL ADMINISTRATOR ACCOUNT', $tbody);
    }

    public function test_an_entry_without_metadata_shows_a_dash(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'auth.login',
            'ip' => '127.0.0.1',
        ]);

        $this->actingAs($admin)->get('/audit')->assertOk()->assertSee('—');
    }

    public function test_the_audit_page_uses_the_shared_security_dashboard_layout(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.unassigned',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 3,
            'metadata' => ['count' => 1],
        ]);

        $this->actingAs($admin)->get('/audit')
            ->assertOk()
            ->assertSee('Search &amp; filter audit events', false)
            ->assertSee('Audit records')
            ->assertSee('View details')
            ->assertSee('Audit event details')
            ->assertSee('Dashboard')
            ->assertSee('id="q"', false)
            ->assertSee('name="origin"', false)
            ->assertSee('name="created_from"', false);
    }

    public function test_the_audit_filters_still_scope_the_audit_trail(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $other = User::factory()->create(['role' => User::ROLE_STAFF, 'name' => 'OTHER ACTOR']);

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.unassigned',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 3,
        ]);

        $older = AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $other->id,
            'occurred_at' => now()->subDay(),
            'action' => 'beneficiary_batch.deleted',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 4,
        ]);

        // created_at is not mass assignable; move the older entry explicitly so
        // the date filters can distinguish it.
        AuditLog::query()->whereKey($older->id)->update(['created_at' => now()->subDay()]);

        $this->actingAs($admin)->get('/audit?user_id='.$other->id)
            ->assertOk()
            ->assertSee('beneficiary_batch #4')
            ->assertDontSee('beneficiary_batch #3');

        $this->actingAs($admin)->get('/audit?action=beneficiary_batch.deleted')
            ->assertOk()
            ->assertSee('beneficiary_batch #4')
            ->assertDontSee('beneficiary_batch #3');

        $this->actingAs($admin)->get('/audit?created_from='.now()->toDateString())
            ->assertOk()
            ->assertSee('beneficiary_batch #3')
            ->assertDontSee('beneficiary_batch #4');
    }
}
