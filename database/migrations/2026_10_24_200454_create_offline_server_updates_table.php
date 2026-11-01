<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('offline_server_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offline_server_id')->constrained('offline_servers')->cascadeOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->bigInteger('size');
            $table->string('sha256', 64);
            $table->integer('files');
            $table->string('label', 120);
            $table->string('status', 20)->default('available');
            $table->timestamps();

            $table->index(['offline_server_id', 'status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offline_server_updates');
    }
};
