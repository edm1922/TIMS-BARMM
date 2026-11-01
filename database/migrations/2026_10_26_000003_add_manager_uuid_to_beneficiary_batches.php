<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('beneficiary_batches', 'manager_uuid')) {
            Schema::table('beneficiary_batches', function (Blueprint $table) {
                $table->uuid('manager_uuid')
                    ->nullable()
                    ->after('manager_id')
                    ->index();
            });
        }

        DB::table('beneficiary_batches')
            ->whereNotNull('manager_id')
            ->select(['id', 'manager_id'])
            ->orderBy('id')
            ->chunkById(100, function ($batches): void {
                foreach ($batches as $batch) {
                    $uuid = DB::table('offline_server_encoders')
                        ->where('id', $batch->manager_id)
                        ->value('encoder_uuid');

                    if (is_string($uuid) && $uuid !== '') {
                        DB::table('beneficiary_batches')
                            ->where('id', $batch->id)
                            ->update(['manager_uuid' => $uuid]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('beneficiary_batches', 'manager_uuid')) {
            Schema::table('beneficiary_batches', function (Blueprint $table) {
                $table->dropColumn('manager_uuid');
            });
        }
    }
};
