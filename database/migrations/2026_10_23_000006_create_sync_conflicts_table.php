<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('conflict_uuid', 36)->unique();
            $table->foreignId('offline_server_id')->constrained('offline_servers')->cascadeOnDelete();
            $table->string('entity_type', 50);
            $table->string('entity_uuid', 36);
            $table->string('origin_server_uuid', 36);
            $table->json('local_payload');
            $table->json('remote_payload');
            $table->json('diff')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->json('resolution')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_uuid']);
            $table->index(['offline_server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
    }
};
