<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the administrator account from .env (placeholders in .env.example).
     */
    public function run(): void
    {
        $username = env('ADMIN_USERNAME') ?: 'admin';
        $email = env('ADMIN_EMAIL') ?: 'admin@example.test';
        $name = env('ADMIN_NAME') ?: 'Administrator';
        $password = env('ADMIN_PASSWORD');

        $generated = false;

        if (! $password) {
            $password = Str::password(16);
            $generated = true;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'username' => $username,
                'password' => Hash::make($password),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ],
        );

        if ($generated && $this->command) {
            $this->command->warn("Admin account: {$username} ({$email})");
            $this->command->warn("Generated password: {$password}  (set ADMIN_PASSWORD in .env and re-run to change it)");
        }
    }
}
