<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_uuid', 36)->unique();
            $table->foreignId('offline_server_id')->nullable()->constrained('offline_servers')->nullOnDelete();
            $table->string('type', 50);
            $table->string('severity', 20)->default('info');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['offline_server_id', 'type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
};
