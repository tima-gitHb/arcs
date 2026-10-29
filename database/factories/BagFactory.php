<?php

namespace Database\Factories;

use App\Models\Bag;
use App\Models\Passenger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bag>
 */
class BagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'passenger_id' => Passenger::factory(),
            'tag_no' => 'ME'.fake()->unique()->numerify('######'),
            'weight_kg' => 20.0,
        ];
    }
}
