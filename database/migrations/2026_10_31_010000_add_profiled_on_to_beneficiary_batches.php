<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-level registration / profiling date. Editing it from the batch Manage
 * page updates the profiling date of the beneficiaries filed in that batch,
 * which is the date the dashboard registration trend uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiary_batches', function (Blueprint $table): void {
            $table->date('profiled_on')->nullable()->after('beneficiary_code_series');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiary_batches', function (Blueprint $table): void {
            $table->dropColumn('profiled_on');
        });
    }
};
