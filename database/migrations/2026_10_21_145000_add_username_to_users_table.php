<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
        });

        // Backfill usernames for existing users
        $users = DB::table('users')->select(['id', 'email', 'name'])->get();
        $used = [];

        foreach ($users as $user) {
            $candidate = null;
            if ($user->email === 'admin@example.test') {
                $candidate = 'admin';
            } elseif ($user->email !== null && str_contains($user->email, '@')) {
                $prefix = strtolower(explode('@', $user->email)[0]);
                $candidate = Str::slug($prefix, '_');
            }

            if (empty($candidate)) {
                $candidate = 'user_'.$user->id;
            }

            $base = $candidate;
            $suffix = 1;
            while (isset($used[$candidate])) {
                $candidate = $base.'_'.$suffix;
                $suffix++;
            }
            $used[$candidate] = true;

            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
