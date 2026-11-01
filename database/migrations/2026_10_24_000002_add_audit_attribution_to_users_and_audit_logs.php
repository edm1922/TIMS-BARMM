<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'uuid')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('uuid', 36)->nullable()->after('id');
            });
        }

        if (! Schema::hasIndex('users', ['uuid'])) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('uuid');
            });
        }

        DB::table('users')->orderBy('id')->each(function ($user): void {
            if ($user->uuid === null || $user->uuid === '') {
                DB::table('users')->where('id', $user->id)->update([
                    'uuid' => (string) Str::uuid(),
                ]);
            }
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('audit_logs', 'event_uuid')) {
                $table->string('event_uuid', 36)->nullable()->after('id');
            }

            if (! Schema::hasColumn('audit_logs', 'actor_uuid')) {
                $table->string('actor_uuid', 36)->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('audit_logs', 'actor_username')) {
                $table->string('actor_username', 50)->nullable()->after('actor_uuid');
            }

            if (! Schema::hasColumn('audit_logs', 'actor_role')) {
                $table->string('actor_role', 20)->nullable()->after('actor_username');
            }

            if (! Schema::hasColumn('audit_logs', 'origin_server_uuid')) {
                $table->string('origin_server_uuid', 36)->nullable()->after('actor_role');
            }

            if (! Schema::hasColumn('audit_logs', 'occurred_at')) {
                $table->timestamp('occurred_at')->nullable()->after('origin_server_uuid');
            }

            if (! Schema::hasColumn('audit_logs', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('occurred_at');
            }
        });

        DB::table('audit_logs')->whereNull('event_uuid')->orderBy('id')->each(function ($log): void {
            DB::table('audit_logs')->where('id', $log->id)->update([
                'event_uuid' => (string) Str::uuid(),
            ]);
        });

        if (! Schema::hasIndex('audit_logs', ['event_uuid'])) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->unique('event_uuid');
            });
        }

        if (! Schema::hasIndex('audit_logs', ['origin_server_uuid'])) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->index('origin_server_uuid');
            });
        }

        if (! Schema::hasIndex('audit_logs', ['synced_at'])) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->index('synced_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['origin_server_uuid']);
            $table->dropIndex(['synced_at']);
            $table->dropUnique(['event_uuid']);
            $table->dropColumn([
                'event_uuid',
                'actor_uuid',
                'actor_username',
                'actor_role',
                'origin_server_uuid',
                'occurred_at',
                'synced_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
