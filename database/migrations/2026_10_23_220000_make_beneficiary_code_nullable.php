<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Retained as a no-op.
 *
 * beneficiary_code must stay required and unique: the generator assigns a
 * monthly DOLE series code on creation, and the unique index is the source of
 * truth for collision recovery in Beneficiary::createWithCode(). Relaxing the
 * constraint here would allow silent duplicate/empty codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
