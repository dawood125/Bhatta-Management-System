<?php

namespace Database\Factories;

use App\Models\Kiln;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kiln>
 */
class KilnFactory extends Factory
{
    protected $model = Kiln::class;

    public function definition(): array
    {
        return [
            'name' => 'Test Kiln '.fake()->unique()->numberBetween(1, 99999),
            'name_ur' => null,
            'address' => null,
            'address_ur' => null,
            'phone' => null,
            'email' => null,
            'logo_path' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
