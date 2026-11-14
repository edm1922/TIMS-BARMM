<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The in-file government ID duplicate marker is
     * "government_id_in_file" (21 characters), which does not fit the original
     * duplicate_kind column (20). Previewing such a workbook fails on MySQL
     * with "Data too long for column 'duplicate_kind'".
     */
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->string('duplicate_kind', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->string('duplicate_kind', 20)->nullable()->change();
        });
    }
};
