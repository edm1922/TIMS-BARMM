<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per upload the central server receives from an offline
        // server. It is the reviewable unit: a submitted Sync Now request,
        // not the individual record, is what an administrator opens.
        Schema::create('sync_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_uuid', 36)->unique();
            $table->foreignId('offline_server_id')->nullable()->constrained('offline_servers')->nullOnDelete();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->string('status', 30)->default('submitted');
            $table->unsignedInteger('total_operations')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->unsignedInteger('validation_error_count')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['offline_server_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });

        // One row per submitted operation (beneficiary or batch). The payload
        // is kept verbatim so a reviewer can compare it with the central
        // record and so a decision can be applied later, after the request
        // was received.
        Schema::create('sync_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_request_id')->constrained('sync_requests')->cascadeOnDelete();
            $table->string('operation_uuid', 36)->unique();
            $table->string('entity_type', 50);
            $table->string('entity_uuid', 36);
            $table->string('operation', 20);
            $table->unsignedBigInteger('base_revision')->nullable();
            $table->json('payload');
            $table->string('status', 30)->default('submitted');
            $table->string('failure_code', 50)->nullable();
            $table->string('duplicate_rule', 50)->nullable();
            $table->json('duplicate_matches')->nullable();
            $table->string('conflict_uuid', 36)->nullable();
            $table->json('result')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['sync_request_id', 'status']);
            $table->index(['entity_type', 'entity_uuid']);
            $table->index(['status', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_request_items');
        Schema::dropIfExists('sync_requests');
    }
};
