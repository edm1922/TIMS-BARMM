<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->id();
            $table->string('change_uuid', 36)->unique();
            $table->foreignId('offline_server_id')->nullable()->constrained('offline_servers')->nullOnDelete();
            $table->string('entity_type', 50);
            $table->string('entity_uuid', 36);
            $table->string('operation', 20);
            $table->string('origin_server_uuid', 36);
            $table->unsignedBigInteger('local_id')->nullable();
            $table->unsignedBigInteger('revision');
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['entity_type', 'entity_uuid']);
            $table->index(['offline_server_id', 'status']);
            $table->index(['origin_server_uuid', 'revision']);
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
    }
};
