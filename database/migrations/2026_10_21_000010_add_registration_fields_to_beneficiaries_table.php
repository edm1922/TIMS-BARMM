<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('address', 255)->nullable()->after('district');
            $table->string('location_code', 10)->nullable()->after('address');

            $table->string('beneficiary_signify', 100)->nullable()->after('beneficiary_type');
            $table->string('mode_of_payment', 100)->nullable()->after('beneficiary_signify');
            $table->string('occupation_other', 150)->nullable()->after('occupation');
            $table->string('id_type_other', 100)->nullable()->after('id_type');

            $table->index('location_code');
        });

        DB::table('beneficiaries')
            ->select(['id', 'barangay', 'city_municipality', 'province', 'district'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $parts = array_filter(
                        [$row->barangay, $row->city_municipality, $row->province, $row->district],
                        fn ($part) => $part !== null && trim((string) $part) !== '',
                    );

                    if ($parts === []) {
                        continue;
                    }

                    DB::table('beneficiaries')->where('id', $row->id)->update([
                        'address' => mb_strtoupper(implode(', ', $parts)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropIndex(['location_code']);
            $table->dropColumn([
                'address',
                'location_code',
                'beneficiary_signify',
                'mode_of_payment',
                'occupation_other',
                'id_type_other',
            ]);
        });
    }
};
