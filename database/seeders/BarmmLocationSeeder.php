<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;
use RuntimeException;

class BarmmLocationSeeder extends Seeder
{
    public const DATA_FILE = 'barmm-locations.json';

    public function run(): void
    {
        $path = database_path('seeders/data/'.self::DATA_FILE);

        if (! is_file($path)) {
            throw new RuntimeException('BARMM location dataset missing: '.$path);
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $payload['locations'] ?? [];

        if ($rows === []) {
            throw new RuntimeException('BARMM location dataset is empty: '.$path);
        }

        $now = now();

        foreach (array_chunk($rows, 1000) as $chunk) {
            $upsert = [];

            foreach ($chunk as $row) {
                $upsert[] = [
                    'psgc_code' => $row['psgc_code'],
                    'barangay' => $row['barangay'],
                    'city_municipality' => $row['city_municipality'],
                    'province' => $row['province'],
                    'full_address' => $row['full_address'],
                    'is_city' => (bool) $row['is_city'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Location::query()->upsert($upsert, ['psgc_code'], [
                'barangay',
                'city_municipality',
                'province',
                'full_address',
                'is_city',
                'updated_at',
            ]);
        }

        $this->command?->info('BARMM locations seeded: '.Location::query()->count().' rows.');
    }
}
