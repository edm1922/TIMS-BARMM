<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiary_batches', function (Blueprint $table) {
            $table->unsignedSmallInteger('program_year')
                ->default((int) now()->year)
                ->after('status');
            $table->index('program_year');
        });

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropUnique(['source_id_number']);
            $table->index('source_id_number');
            $table->unique(
                ['origin_server_uuid', 'beneficiary_batch_id', 'source_id_number'],
                'beneficiaries_origin_batch_source_unique',
            );
            $table->index(
                ['beneficiary_batch_id', 'source_id_number'],
                'beneficiaries_batch_source_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropUnique('beneficiaries_origin_batch_source_unique');
            $table->dropIndex('beneficiaries_batch_source_index');
            $table->dropIndex(['source_id_number']);
            $table->unique('source_id_number');
        });

        Schema::table('beneficiary_batches', function (Blueprint $table) {
            $table->dropIndex(['program_year']);
            $table->dropColumn('program_year');
        });
    }
};
