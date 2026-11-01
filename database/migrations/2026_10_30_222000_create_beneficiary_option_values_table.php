<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Values added at runtime to the controlled vocabularies, e.g. an
 * unrecognized "Type of ID" from an Excel import that an administrator
 * accepted as a new standard option.
 *
 * The canonical option lists still live in BeneficiaryOptions; this table only
 * stores the extras and is merged into those lists for validation, dropdowns,
 * and import warning detection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiary_option_values', function (Blueprint $table): void {
            $table->id();
            $table->string('field', 50);
            $table->string('value', 150);
            $table->string('normalized', 150);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['field', 'normalized']);
            $table->index('field');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiary_option_values');
    }
};
