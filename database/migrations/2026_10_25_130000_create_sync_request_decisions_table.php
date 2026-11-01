<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only history of every human decision on a submitted record.
        // Rows are never updated or deleted: a resubmission adds new rows, so
        // the earlier decision stays on record with its reviewer and reason.
        Schema::create('sync_request_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_request_id')->constrained('sync_requests')->cascadeOnDelete();
            $table->foreignId('sync_request_item_id')->constrained('sync_request_items')->cascadeOnDelete();
            $table->string('request_uuid', 36);
            $table->string('operation_uuid', 36);
            $table->string('entity_type', 50);
            $table->string('entity_uuid', 36);
            $table->string('source_server_uuid', 36)->nullable();
            $table->string('decision', 20);
            $table->string('action', 30)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sync_request_id', 'decided_at']);
            $table->index(['entity_type', 'entity_uuid']);
            $table->index('decided_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_request_decisions');
    }
};
