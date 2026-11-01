<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-batch beneficiary ID series: the next code to issue to a
 * beneficiary created in this batch. NULL means the automatic series is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiary_batches', function (Blueprint $table): void {
            $table->string('beneficiary_code_series', 64)->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiary_batches', function (Blueprint $table): void {
            $table->dropColumn('beneficiary_code_series');
        });
    }
};
