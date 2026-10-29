<?php

namespace Database\Factories;

use App\Models\Flight;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pnr' => fake()->unique()->regexify('[A-HJ-NP-Z2-9]{6}'),
            'flight_id' => Flight::factory(),
            'created_by' => User::factory(),
        ];
    }
}
