<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('excel_row');
            $table->enum('outcome', [
                'new', 'update', 'unchanged', 'duplicate', 'invalid', 'review', 'skipped',
            ]);
            $table->foreignId('matched_beneficiary_id')->nullable()
                ->constrained('beneficiaries')->nullOnDelete();
            $table->json('candidate_ids')->nullable();
            $table->json('messages')->nullable();
            $table->enum('operator_decision', ['insert', 'update', 'skip', 'review'])->nullable();
            $table->boolean('applied')->default(false);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'excel_row']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
