<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_servers', function (Blueprint $table) {
            $table->id();
            $table->string('server_uuid', 36)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('api_token_hash', 64)->nullable()->unique();
            $table->timestamp('api_token_last_used_at')->nullable();
            $table->string('app_version', 50)->nullable();
            $table->unsignedInteger('protocol_version')->default(1);
            $table->unsignedInteger('schema_version')->default(1);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_servers');
    }
};
