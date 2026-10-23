<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->foreignId('beneficiary_batch_id')->nullable()->after('import_batch_id')
                ->constrained('beneficiary_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('beneficiary_batch_id');
        });
    }
};
