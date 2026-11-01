<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use Illuminate\Database\Eloquent\Factories\Factory;

class BeneficiaryFactory extends Factory
{
    protected $model = Beneficiary::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'birthdate' => fake()->dateTimeBetween('-70 years', '-10 years')->format('Y-m-d'),
            'barangay' => fake()->streetName(),
            'city_municipality' => 'Test City',
            'province' => 'Test Province',
            'sex' => fake()->randomElement(['MALE', 'FEMALE']),
            'civil_status' => fake()->randomElement(['SINGLE', 'MARRIED', 'WIDOW']),
            'occupation' => fake()->randomElement(['Farmer', 'Vendor', 'Laborer', 'Fisherfolk']),
            'duplicate_status' => 'none',
            'beneficiary_code' => '',
        ];
    }

    public function underage(): static
    {
        return $this->state(fn () => ['birthdate' => now()->subYears(16)->toDateString()]);
    }

    public function withBirthdate(string $birthdate): static
    {
        return $this->state(fn () => ['birthdate' => $birthdate]);
    }

    public function named(string $first, ?string $middle, string $last): static
    {
        return $this->state(fn () => [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
        ]);
    }
}
