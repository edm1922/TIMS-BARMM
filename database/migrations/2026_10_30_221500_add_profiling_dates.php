<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beneficiary profiling date support for the Excel import.
 *
 * `beneficiaries.profiled_on` — the date the beneficiary was originally
 * profiled/registered (set by the importer from the "Beneficiary Profiling
 * Date" picker). NULL falls back to `created_at` for reporting.
 *
 * `import_batches.profiling_date` — the profiling date chosen for a workbook,
 * carried from upload through preview to apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            $table->date('profiled_on')->nullable()->after('birthdate_raw');
            $table->index('profiled_on');
        });

        Schema::table('import_batches', function (Blueprint $table): void {
            $table->date('profiling_date')->nullable()->after('header_row');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            $table->dropIndex(['profiled_on']);
            $table->dropColumn('profiled_on');
        });

        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn('profiling_date');
        });
    }
};
