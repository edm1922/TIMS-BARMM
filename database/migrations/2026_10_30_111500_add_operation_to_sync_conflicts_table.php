<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the requested operation (and the originating operation UUID) on
     * every sync conflict, so deletion-approval requests are identifiable and
     * their decisions can be written back to the requesting operation's
     * receipt.
     */
    public function up(): void
    {
        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->string('operation', 30)->nullable()->after('entity_uuid');
            $table->uuid('operation_uuid')->nullable()->after('operation');
        });
    }

    public function down(): void
    {
        Schema::table('sync_conflicts', function (Blueprint $table) {
            $table->dropColumn(['operation', 'operation_uuid']);
        });
    }
};
