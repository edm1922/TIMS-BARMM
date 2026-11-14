<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A government ID may repeat once per coverage year; it is no longer
     * globally unique for the origin server.
     */
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            if (Schema::hasIndex('beneficiaries', ['source_id_no', 'origin_server_uuid'], 'unique')) {
                $table->dropUnique(['source_id_no', 'origin_server_uuid']);
            }

            if (! Schema::hasIndex('beneficiaries', ['source_id_no', 'origin_server_uuid', 'coverage_year'], 'unique')) {
                $table->unique(['source_id_no', 'origin_server_uuid', 'coverage_year']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table): void {
            if (Schema::hasIndex('beneficiaries', ['source_id_no', 'origin_server_uuid', 'coverage_year'], 'unique')) {
                $table->dropUnique(['source_id_no', 'origin_server_uuid', 'coverage_year']);
            }

            if (! Schema::hasIndex('beneficiaries', ['source_id_no', 'origin_server_uuid'], 'unique')) {
                $table->unique(['source_id_no', 'origin_server_uuid']);
            }
        });
    }
};
