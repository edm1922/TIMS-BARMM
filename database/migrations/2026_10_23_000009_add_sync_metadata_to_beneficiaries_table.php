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
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('global_uuid', 36)->nullable()->unique()->after('id');
            $table->string('origin_server_uuid', 36)->nullable()->after('global_uuid');
            $table->unsignedBigInteger('revision')->default(1)->after('origin_server_uuid');
            $table->unsignedBigInteger('server_revision')->default(1)->after('revision');
            $table->string('sync_status', 20)->default('synced')->after('server_revision');
            $table->string('conflict_status', 20)->default('none')->after('sync_status');
            $table->string('last_operation_uuid', 36)->nullable()->after('conflict_status');
        });

        $now = now();
        $central = config('sync.central_server_uuid');
        DB::table('beneficiaries')->orderBy('id')->chunkById(500, function ($rows) use ($central, $now) {
            foreach ($rows as $row) {
                DB::table('beneficiaries')->where('id', $row->id)->update([
                    'global_uuid' => $row->global_uuid ?: (string) Str::uuid(),
                    'origin_server_uuid' => $row->origin_server_uuid ?: $central,
                    'revision' => $row->revision ?: 1,
                    'server_revision' => $row->server_revision ?: 1,
                    'sync_status' => $row->sync_status ?: 'synced',
                    'conflict_status' => $row->conflict_status ?: 'none',
                    'updated_at' => $row->updated_at ?: $now,
                ]);
            }
        });

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->index('origin_server_uuid');
            $table->index(['sync_status', 'conflict_status']);
            $table->index('last_operation_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropIndex(['origin_server_uuid']);
            $table->dropIndex(['sync_status', 'conflict_status']);
            $table->dropIndex(['last_operation_uuid']);
            $table->dropUnique(['global_uuid']);
            $table->dropColumn([
                'global_uuid',
                'origin_server_uuid',
                'revision',
                'server_revision',
                'sync_status',
                'conflict_status',
                'last_operation_uuid',
            ]);
        });
    }
};
