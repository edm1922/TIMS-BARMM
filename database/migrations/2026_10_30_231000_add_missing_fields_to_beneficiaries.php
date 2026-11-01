<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks which beneficiary fields were deliberately left blank (for example
 * a government ID bypassed from an import conflict) so the record can be
 * surfaced under the "Flag → Missing fields" filter and fixed later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            $table->string('missing_fields', 255)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            $table->dropColumn('missing_fields');
        });
    }
};
