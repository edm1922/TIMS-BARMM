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
        Schema::table('beneficiary_batches', function (Blueprint $table) {
            $table->string('global_uuid', 36)->nullable()->unique()->after('id');
            $table->string('origin_server_uuid', 36)->nullable()->after('global_uuid');
            $table->unsignedBigInteger('revision')->default(1)->after('origin_server_uuid');
            $table->unsignedBigInteger('server_revision')->default(1)->after('revision');
            $table->string('sync_status', 20)->default('synced')->after('server_revision');
            $table->string('conflict_status', 20)->default('none')->after('sync_status');
            $table->string('last_operation_uuid', 36)->nullable()->after('conflict_status');
            $table->softDeletes()->after('updated_at');
        });

        $now = now();
        $central = config('sync.central_server_uuid');
        DB::table('beneficiary_batches')->orderBy('id')->chunkById(500, function ($rows) use ($central, $now) {
            foreach ($rows as $row) {
                DB::table('beneficiary_batches')->where('id', $row->id)->update([
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

        Schema::table('beneficiary_batches', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['origin_server_uuid', 'name'], 'beneficiary_batches_origin_name_unique');
            $table->index('origin_server_uuid');
            $table->index(['sync_status', 'conflict_status']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiary_batches', function (Blueprint $table) {
            $table->dropIndex(['origin_server_uuid']);
            $table->dropIndex(['sync_status', 'conflict_status']);
            $table->dropIndex(['deleted_at']);
            $table->dropUnique('beneficiary_batches_origin_name_unique');
            $table->unique(['name']);
            $table->dropUnique(['global_uuid']);
            $table->dropSoftDeletes();
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
