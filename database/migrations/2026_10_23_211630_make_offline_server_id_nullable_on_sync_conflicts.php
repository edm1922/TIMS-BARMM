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
        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->foreignId('offline_server_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->foreignId('offline_server_id')->nullable(false)->change();
        });
    }
};