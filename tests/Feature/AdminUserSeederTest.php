<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SyncIdentity;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, string>
     */
    private array $originalAdminEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ADMIN_USERNAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD'] as $key) {
            $this->originalAdminEnvironment[$key] = (string) ($_ENV[$key] ?? $_SERVER[$key] ?? '');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalAdminEnvironment as $key => $value) {
            $this->setAdminEnvironment($key, $value);
        }

        parent::tearDown();
    }

    /**
     * The environment helper reads the server and environment variables before
     * the process environment, so all three have to be written.
     */
    private function setAdminEnvironment(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;

        if ($value === '') {
            putenv($key);
        } else {
            putenv($key.'='.$value);
        }
    }

    private function runSeeder(): void
    {
        $this->seed(AdminUserSeeder::class);
    }

    public function test_it_creates_the_administrator_when_the_table_is_empty(): void
    {
        config(['sync.instance_mode' => 'online']);
        $this->setAdminEnvironment('ADMIN_USERNAME', 'admin');
        $this->setAdminEnvironment('ADMIN_EMAIL', 'admin@example.test');
        $this->setAdminEnvironment('ADMIN_PASSWORD', 'SecretPass!2026');

        $this->runSeeder();

        $user = User::query()->where('username', 'admin')->first();

        $this->assertNotNull($user);
        $this->assertSame('admin@example.test', $user->email);
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(password_verify('SecretPass!2026', $user->password));
    }

    public function test_it_updates_the_existing_administrator_instead_of_failing_on_the_username(): void
    {
        config(['sync.instance_mode' => 'online']);
        $this->setAdminEnvironment('ADMIN_USERNAME', 'admin');
        $this->setAdminEnvironment('ADMIN_PASSWORD', 'SecretPass!2026');

        User::factory()->create([
            'username' => 'admin',
            'email' => 'admin@example.test',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->setAdminEnvironment('ADMIN_EMAIL', 'reinstalled@example.test');
        $this->runSeeder();

        $this->assertSame(1, User::query()->count());

        $user = User::query()->where('username', 'admin')->first();

        $this->assertNotNull($user);
        $this->assertSame('reinstalled@example.test', $user->email);
        $this->assertTrue(password_verify('SecretPass!2026', $user->password));
    }

    public function test_it_finds_the_existing_administrator_by_address_when_the_username_differs(): void
    {
        config(['sync.instance_mode' => 'online']);
        $this->setAdminEnvironment('ADMIN_USERNAME', 'fieldadmin');
        $this->setAdminEnvironment('ADMIN_EMAIL', 'field@example.test');
        $this->setAdminEnvironment('ADMIN_PASSWORD', 'SecretPass!2026');

        User::factory()->create([
            'username' => 'oldhandle',
            'email' => 'field@example.test',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->runSeeder();

        $this->assertSame(1, User::query()->count());

        $user = User::query()->where('email', 'field@example.test')->first();

        $this->assertNotNull($user);
        $this->assertSame('fieldadmin', $user->username);
    }

    public function test_it_never_takes_over_an_address_that_belongs_to_another_account(): void
    {
        config(['sync.instance_mode' => 'online']);
        $this->setAdminEnvironment('ADMIN_USERNAME', 'admin');
        $this->setAdminEnvironment('ADMIN_EMAIL', 'someone.else@example.test');
        $this->setAdminEnvironment('ADMIN_PASSWORD', 'SecretPass!2026');

        User::factory()->create([
            'username' => 'admin',
            'email' => 'admin@example.test',
            'role' => User::ROLE_ADMIN,
        ]);

        User::factory()->create([
            'username' => 'other',
            'email' => 'someone.else@example.test',
            'role' => User::ROLE_STAFF,
        ]);

        $this->runSeeder();

        $this->assertSame(2, User::query()->count());

        $admin = User::query()->where('username', 'admin')->first();
        $other = User::query()->where('username', 'other')->first();

        $this->assertSame('admin@example.test', $admin->email);
        $this->assertSame('someone.else@example.test', $other->email);
        $this->assertSame(User::ROLE_STAFF, $other->role);
    }

    public function test_it_keeps_the_existing_password_when_none_is_configured(): void
    {
        config(['sync.instance_mode' => 'online']);
        $this->setAdminEnvironment('ADMIN_USERNAME', 'admin');
        $this->setAdminEnvironment('ADMIN_EMAIL', 'admin@example.test');
        $this->setAdminEnvironment('ADMIN_PASSWORD', '');

        $existing = User::factory()->create([
            'username' => 'admin',
            'email' => 'admin@example.test',
            'role' => User::ROLE_ADMIN,
            'password' => 'the-original-password',
        ]);

        $hashBefore = $existing->password;

        $this->runSeeder();

        $user = User::query()->where('username', 'admin')->first();

        $this->assertNotNull($user);
        $this->assertSame($hashBefore, $user->password);
        $this->assertTrue(password_verify('the-original-password', $user->password));
        $this->assertSame($existing->id, $user->id);
    }

    public function test_it_creates_a_manager_on_an_offline_installation(): void
    {
        config(['sync.instance_mode' => 'offline']);
        $this->setAdminEnvironment('ADMIN_USERNAME', 'admin');
        $this->setAdminEnvironment('ADMIN_EMAIL', 'manager@example.test');
        $this->setAdminEnvironment('ADMIN_PASSWORD', 'SecretPass!2026');

        $this->assertTrue(SyncIdentity::isOffline());

        $this->runSeeder();

        $user = User::query()->where('username', 'admin')->first();

        $this->assertNotNull($user);
        $this->assertSame(User::ROLE_MANAGER, $user->role);
    }

    public function test_it_seeds_no_account_on_an_offline_installation_without_a_configured_password(): void
    {
        config(['sync.instance_mode' => 'offline']);
        $this->setAdminEnvironment('ADMIN_USERNAME', 'admin');
        $this->setAdminEnvironment('ADMIN_EMAIL', 'manager@example.test');
        $this->setAdminEnvironment('ADMIN_PASSWORD', '');

        $this->runSeeder();

        $this->assertSame(0, User::query()->count());
    }
}
