<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offline_server_id')->constrained('offline_servers')->cascadeOnDelete();
            $table->string('direction', 20);
            $table->unsignedBigInteger('last_revision');
            $table->string('cursor', 100)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['offline_server_id', 'direction']);
            $table->index('last_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_checkpoints');
    }
};
