<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Beneficiary capacity per batch.
 *
 * `capacity` is the maximum number of active beneficiaries a batch may hold;
 * NULL means no limit. Existing batches are backfilled with the number of
 * beneficiaries they currently hold, so they can no longer grow beyond today's
 * membership until an administrator raises the limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiary_batches', function (Blueprint $table): void {
            $table->unsignedInteger('capacity')->nullable()->after('status');
        });

        DB::statement(
            'UPDATE beneficiary_batches SET capacity = ('
            .'SELECT COUNT(*) FROM beneficiaries '
            .'WHERE beneficiaries.beneficiary_batch_id = beneficiary_batches.id '
            .'AND beneficiaries.archived_at IS NULL)'
        );
    }

    public function down(): void
    {
        Schema::table('beneficiary_batches', function (Blueprint $table): void {
            $table->dropColumn('capacity');
        });
    }
};
