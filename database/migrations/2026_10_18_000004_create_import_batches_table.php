<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename', 255);
            $table->string('worksheet', 100)->nullable();
            $table->unsignedInteger('header_row')->nullable();
            $table->enum('status', ['uploaded', 'previewed', 'applied', 'failed', 'cancelled'])
                ->default('uploaded');
            $table->boolean('use_source_id_as_key')->default(true);
            $table->json('mapping')->nullable();
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('flagged')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('stored_path')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
