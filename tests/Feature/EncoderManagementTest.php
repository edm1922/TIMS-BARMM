<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EncoderManagementTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_MANAGER]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    private function encoder(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ENCODER,
            'username' => 'm_delacruz',
        ]);
    }

    public function test_the_encoders_page_is_available_to_managers_on_offline(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $this->actingAs($this->manager())
            ->get(route('encoders.index'))
            ->assertOk()
            ->assertSee('Encoder accounts');
    }

    public function test_encoders_page_is_not_available_on_central_server(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('encoders.index'))
            ->assertNotFound();
    }

    public function test_staff_cannot_manage_encoder_accounts(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $this->actingAs($this->staff())
            ->get(route('encoders.index'))
            ->assertForbidden();
    }

    public function test_a_manager_can_create_an_encoder_account(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('encoders.store'), [
                'name' => 'Maria Dela Cruz',
                'username' => 'm_delacruz',
                'password' => 'encoder-password',
                'password_confirmation' => 'encoder-password',
            ])
            ->assertRedirect(route('encoders.index'))
            ->assertSessionHas('success');

        $encoder = User::query()->where('username', 'm_delacruz')->firstOrFail();

        $this->assertSame(User::ROLE_ENCODER, $encoder->role);
        $this->assertTrue($encoder->is_active);
        $this->assertNotNull($encoder->uuid);
        $this->assertStringStartsWith('m_delacruz@', $encoder->email);
    }

    public function test_creating_an_encoder_account_is_recorded_in_the_audit_log(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('encoders.store'), [
                'name' => 'Maria Dela Cruz',
                'username' => 'm_delacruz',
                'password' => 'encoder-password',
                'password_confirmation' => 'encoder-password',
            ]);

        $encoder = User::query()->where('username', 'm_delacruz')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'encoder.created',
            'entity_type' => 'user',
            'entity_id' => $encoder->id,
            'actor_username' => $manager->username,
        ]);
    }

    public function test_the_username_must_be_unique(): void
    {
        config()->set('sync.instance_mode', 'offline');

        User::factory()->create(['username' => 'm_delacruz', 'role' => User::ROLE_ENCODER]);

        $this->actingAs($this->manager())
            ->post(route('encoders.store'), [
                'name' => 'Another Maria',
                'username' => 'm_delacruz',
                'password' => 'encoder-password',
                'password_confirmation' => 'encoder-password',
            ])
            ->assertSessionHasErrors('username');
    }

    public function test_an_encoder_can_be_deactivated_without_losing_the_audit_identity(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $encoder = User::factory()->create([
            'username' => 'm_delacruz',
            'role' => User::ROLE_ENCODER,
        ]);

        $uuid = $encoder->uuid;

        $this->actingAs($this->manager())
            ->put(route('encoders.update', $encoder), [
                'name' => 'Maria Dela Cruz',
                'username' => 'm_delacruz',
                'is_active' => '0',
            ])
            ->assertRedirect(route('encoders.index'));

        $encoder->refresh();

        $this->assertFalse($encoder->is_active);
        $this->assertSame($uuid, $encoder->uuid);
        $this->assertDatabaseHas('audit_logs', ['action' => 'encoder.deactivated']);
    }

    public function test_a_non_encoder_account_cannot_be_updated_through_the_encoder_page(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);

        $this->actingAs($this->manager())
            ->put(route('encoders.update', $staff), [
                'name' => 'Staff',
                'username' => $staff->username,
            ])
            ->assertNotFound();
    }

    public function test_an_encoder_action_is_attributable_in_the_audit_log(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $encoder = $this->encoder();

        $this->actingAs($encoder)->get('/beneficiaries')->assertOk();

        $this->actingAs($encoder)->post('/logout');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.logout',
            'actor_uuid' => $encoder->uuid,
            'actor_username' => 'm_delacruz',
            'actor_role' => User::ROLE_ENCODER,
        ]);
    }

    public function test_every_audit_record_carries_an_origin_and_an_event_identifier(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $manager = $this->manager();

        $this->actingAs($manager)->post('/logout');

        $log = AuditLog::query()->latest('id')->firstOrFail();

        $this->assertNotNull($log->event_uuid);
        $this->assertNotNull($log->origin_server_uuid);
        $this->assertNotNull($log->occurred_at);
        $this->assertSame($manager->uuid, $log->actor_uuid);
    }

    public function test_the_encoder_page_uses_the_shared_management_workspace_layout(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $manager = $this->manager();
        $encoder = $this->encoder();

        $this->actingAs($manager)->get(route('encoders.index'))
            ->assertOk()
            ->assertSee('Total encoders')
            ->assertSee('Encoder accounts')
            ->assertSee('Create an encoder account')
            ->assertSee('Recent encoder account activity')
            ->assertSee('m_delacruz')
            ->assertSee('id="new-name"', false)
            ->assertSee('id="new-username"', false)
            ->assertSee('id="new-password"', false)
            ->assertSee('id="name-'.$encoder->id.'"', false)
            ->assertSee(route('encoders.store'), false);
    }
}
