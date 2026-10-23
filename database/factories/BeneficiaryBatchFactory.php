<?php

namespace Database\Factories;

use App\Models\BeneficiaryBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BeneficiaryBatch>
 */
class BeneficiaryBatchFactory extends Factory
{
    protected $model = BeneficiaryBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Batch '.fake()->unique()->numerify('##'),
            'description' => fake()->optional()->sentence(4),
            'status' => BeneficiaryBatch::STATUS_OPEN,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => BeneficiaryBatch::STATUS_CLOSED]);
    }

    public function named(string $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }
}
