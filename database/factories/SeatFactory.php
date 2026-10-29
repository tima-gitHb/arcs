<?php

namespace Database\Factories;

use App\Enums\CabinClass;
use App\Models\Aircraft;
use App\Models\Seat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seat>
 */
class SeatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'aircraft_id' => Aircraft::factory(),
            'seat_no' => fake()->unique()->regexify('[1-3][0-9][A-F]'),
            'cabin_class' => CabinClass::Economy,
        ];
    }
}
