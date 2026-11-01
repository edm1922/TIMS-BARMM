<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate-review state for imported rows.
 *
 * - duplicate_kind: what triggered the duplicate flag (government_id,
 *   name_birthdate, name_weak, in_file).
 * - review_action: the administrator's decision (approve_new, update_existing,
 *   skip).
 * - reviewed_by / reviewed_at: who reviewed the duplicate and when.
 * - apply_error: per-row failure message when the row could not be applied
 *   (for example a government-ID race) without failing the whole batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->string('duplicate_kind', 20)->nullable()->after('outcome');
            $table->string('review_action', 20)->nullable()->after('candidate_ids');
            $table->foreignId('reviewed_by')->nullable()->after('review_action')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('apply_error', 255)->nullable()->after('applied');
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['duplicate_kind', 'review_action', 'reviewed_by', 'reviewed_at', 'apply_error']);
        });
    }
};
