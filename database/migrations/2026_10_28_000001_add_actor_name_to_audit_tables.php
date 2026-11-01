<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit entries and the offline account registry carry the person's name,
     * not only their username, so the trail stays readable on the central
     * server after it synchronizes.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_name')->nullable()->after('actor_username');
        });

        Schema::table('offline_server_encoders', function (Blueprint $table) {
            $table->string('name')->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('actor_name');
        });

        Schema::table('offline_server_encoders', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
