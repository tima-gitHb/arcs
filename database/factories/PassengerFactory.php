<?php

namespace Database\Factories;

use App\Enums\PassengerType;
use App\Models\Passenger;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Passenger>
 */
class PassengerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'type' => PassengerType::Adult,
        ];
    }
}
