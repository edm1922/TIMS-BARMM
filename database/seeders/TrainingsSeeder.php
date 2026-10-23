<?php

namespace Database\Seeders;

use App\Models\Training;
use Illuminate\Database\Seeder;

class TrainingsSeeder extends Seeder
{
    /**
     * Seed the TESDA training reference list from the source workbook extract.
     * Reference data only — contains no beneficiary personal information.
     */
    public function run(): void
    {
        $rows = require base_path('database/seeders/data/trainings.php');

        foreach ($rows as [$category, $name]) {
            Training::updateOrCreate([
                'category' => $category,
                'name' => $name,
            ]);
        }
    }
}
