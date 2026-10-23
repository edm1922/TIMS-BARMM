<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Note: model events must run (no WithoutModelEvents) so beneficiary
     * codes are generated exactly as they are in production.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            BarmmLocationSeeder::class,
            TrainingsSeeder::class,
            DemoBeneficiariesSeeder::class,
        ]);
    }
}
