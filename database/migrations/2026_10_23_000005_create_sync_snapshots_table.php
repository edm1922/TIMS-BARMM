<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_uuid', 36)->unique();
            $table->foreignId('offline_server_id')->nullable()->constrained('offline_servers')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('revision_from')->nullable();
            $table->unsignedBigInteger('revision_to')->nullable();
            $table->json('manifest')->nullable();
            $table->unsignedBigInteger('row_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['offline_server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_snapshots');
    }
};
