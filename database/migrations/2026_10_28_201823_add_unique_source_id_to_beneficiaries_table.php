<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            // Prevent duplicate beneficiary records: same government ID number
            // cannot be registered twice on the same server. We allow the same
            // ID number on different servers because each offline installation
            // manages its own scope independently.
            $table->unique(['source_id_no', 'origin_server_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropUnique(['source_id_no', 'origin_server_uuid']);
        });
    }
};
