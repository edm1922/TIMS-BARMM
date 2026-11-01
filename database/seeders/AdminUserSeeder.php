<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\SyncIdentity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the administrator/manager account from .env (placeholders in .env.example).
     * On an offline installation the role is 'manager'; on the central server it is 'admin'.
     *
     * The account is resolved by username and by address, so re-running the
     * seeder on an installation that already has an administrator updates that
     * account instead of failing on the unique constraints.
     */
    public function run(): void
    {
        $username = env('ADMIN_USERNAME') ?: 'admin';
        $email = env('ADMIN_EMAIL') ?: 'admin@example.test';
        $name = env('ADMIN_NAME') ?: 'Administrator';
        $password = env('ADMIN_PASSWORD');
        $configuredPassword = is_string($password) && $password !== '';

        $role = SyncIdentity::isOffline() ? User::ROLE_MANAGER : User::ROLE_ADMIN;

        // On an offline installation the first Manager is created by the
        // browser onboarding flow, together with the area and the enrollment
        // token, so the account is only seeded here when an administrator has
        // deliberately set a password in .env (an unattended/silent install).
        if (SyncIdentity::isOffline() && ! $configuredPassword) {
            if ($this->command) {
                $this->command->warn('Offline installation: no Manager seeded. Open the site in a browser to run first-time onboarding.');
            }

            return;
        }

        $byUsername = User::query()->where('username', $username)->first();
        $byEmail = User::query()->where('email', $email)->first();

        $user = $byUsername ?? $byEmail;

        if ($user === null) {
            if (! $configuredPassword) {
                $password = Str::password(16);
            }

            $user = User::create([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => $role,
                'is_active' => true,
            ]);

            $label = $role === User::ROLE_MANAGER ? 'Manager' : 'Admin';

            if ($this->command) {
                $this->command->warn("{$label} account: {$username} ({$email})");
            }

            if (! $configuredPassword && $this->command) {
                $this->command->warn("Generated password: {$password}  (set ADMIN_PASSWORD in .env and re-run to change it)");
            }

            return;
        }

        $attributes = [
            'name' => $name,
            'role' => $role,
            'is_active' => true,
        ];

        // The username is the login handle, so it is only taken over when no
        // other account already answers to it.
        if ($byUsername === null) {
            $attributes['username'] = $username;
        }

        // The address is only taken over when it does not belong to another
        // account, so an unrelated administrator is never overwritten.
        if ($byEmail === null) {
            $attributes['email'] = $email;
        }

        if ($configuredPassword) {
            $attributes['password'] = Hash::make($password);
        }

        $user->forceFill($attributes)->save();

        if ($this->command) {
            $this->command->warn("{$user->username} ({$user->email}) already exists - the account was updated instead.");

            if (! $configuredPassword) {
                $this->command->warn('The existing password was left unchanged. Set ADMIN_PASSWORD in .env and re-run to set a new one.');
            }
        }
    }
}
