<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->string('beneficiary_code', 48)->unique();
            $table->string('source_id_number', 64)->nullable()->unique();
            $table->unsignedInteger('source_row_no')->nullable();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('extension_name', 50)->nullable();

            $table->date('birthdate')->nullable();
            $table->string('birthdate_raw', 32)->nullable();

            $table->string('barangay', 100)->nullable();
            $table->string('city_municipality', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('district', 100)->nullable();

            $table->string('id_type', 50)->nullable();
            $table->string('source_id_no', 64)->nullable();
            $table->string('contact_no', 20)->nullable();
            $table->string('contact_no_raw', 32)->nullable();
            $table->string('epayment_account_no', 100)->nullable();

            $table->string('beneficiary_type', 100)->nullable();
            $table->string('occupation', 100)->nullable();
            $table->string('sex', 10)->nullable();
            $table->string('civil_status', 20)->nullable();
            $table->unsignedTinyInteger('age_source')->nullable();
            $table->decimal('avg_monthly_income', 10, 2)->nullable();
            $table->string('dependent_name', 150)->nullable();
            $table->string('employment_interest', 255)->nullable();
            $table->string('skills_training_needed', 150)->nullable();

            $table->string('photo_path')->nullable();
            $table->string('signature_path')->nullable();

            $table->enum('duplicate_status', ['none', 'candidate', 'confirmed_duplicate', 'cleared'])
                ->default('none');
            $table->text('duplicate_note')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->string('archive_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
            $table->index('barangay');
            $table->index('archived_at');
            $table->index('duplicate_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiaries');
    }
};
