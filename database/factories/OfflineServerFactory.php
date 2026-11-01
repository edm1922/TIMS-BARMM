<?php

namespace Database\Factories;

use App\Models\OfflineServer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OfflineServer>
 */
class OfflineServerFactory extends Factory
{
    protected $model = OfflineServer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_uuid' => (string) Str::uuid(),
            'name' => 'Offline Server '.fake()->unique()->numerify('##'),
            'description' => fake()->optional()->sentence(4),
            'status' => OfflineServer::STATUS_ACTIVE,
            'protocol_version' => 1,
            'schema_version' => 1,
            'enrolled_at' => now(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => OfflineServer::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => OfflineServer::STATUS_SUSPENDED,
        ]);
    }
}
