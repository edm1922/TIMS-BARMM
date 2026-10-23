<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('psgc_code', 10)->unique();
            $table->string('barangay', 150);
            $table->string('city_municipality', 150);
            $table->string('province', 150);
            $table->string('full_address', 500);
            $table->boolean('is_city')->default(false);
            $table->timestamps();

            $table->index('barangay');
            $table->index('city_municipality');
            $table->index('province');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
