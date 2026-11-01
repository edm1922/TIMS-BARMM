<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiary_batches', function (Blueprint $table) {
            $table->foreignId('manager_id')
                ->nullable()
                ->after('created_by')
                ->constrained('offline_server_encoders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('beneficiary_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
        });
    }
};
