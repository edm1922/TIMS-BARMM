<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->id();
            $table->string('operation_uuid', 36)->unique();
            $table->string('entity_type', 50);
            $table->string('entity_uuid', 36);
            $table->string('operation', 20);
            $table->string('origin_server_uuid', 36);
            $table->unsignedBigInteger('local_id')->nullable();
            $table->unsignedBigInteger('base_revision')->default(0);
            $table->json('payload');
            $table->json('changed_fields')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_uuid']);
            $table->index(['status', 'available_at']);
            $table->index(['origin_server_uuid', 'base_revision']);
        });

        Schema::create('sync_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_settings');
        Schema::dropIfExists('sync_operations');
    }
};
