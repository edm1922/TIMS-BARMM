<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_server_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offline_server_id')->constrained('offline_servers')->cascadeOnDelete();
            $table->string('scope_type', 20)->default('psgc');
            $table->string('location_code', 10)->nullable();
            $table->string('province', 150)->nullable();
            $table->string('city_municipality', 150)->nullable();
            $table->string('barangay', 150)->nullable();
            $table->timestamps();

            $table->unique(['offline_server_id', 'scope_type', 'location_code'], 'oss_scopes_unique');
            $table->index('location_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_server_scopes');
    }
};
