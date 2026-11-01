<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_server_encoders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offline_server_id')->constrained('offline_servers')->cascadeOnDelete();
            $table->string('encoder_uuid', 36);
            $table->string('username', 50);
            $table->string('role', 20)->default('encoder');
            $table->boolean('is_active')->default(true);
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->unique(['offline_server_id', 'encoder_uuid'], 'offline_server_encoder_unique');
            $table->index('username');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_server_encoders');
    }
};
