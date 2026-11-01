<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable decision history for sync conflicts: every resolution (and
     * every automatic closure) appends a row, so a conflict's full decision
     * trail survives later retries and re-submissions.
     */
    public function up(): void
    {
        Schema::create('sync_conflict_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('conflict_uuid');
            $table->foreignId('sync_conflict_id')->nullable()->constrained('sync_conflicts')->nullOnDelete();
            $table->string('entity_type', 50);
            $table->uuid('entity_uuid');
            $table->string('operation', 30)->nullable();
            $table->string('decision', 50);
            $table->text('reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('conflict_uuid');
            $table->index(['entity_type', 'entity_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflict_decisions');
    }
};
