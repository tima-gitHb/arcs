<?php

namespace Database\Factories;

use App\Models\Aircraft;
use App\Models\Flight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flight>
 */
class FlightFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $departure = now()->addDay()->setTime(12, 0);

        return [
            'flight_no' => config('arcs.airline.code').fake()->numerify('###'),
            'origin' => 'BEY',
            'destination' => fake()->randomElement(['CDG', 'DXB', 'LHR', 'FCO']),
            'aircraft_id' => Aircraft::factory(),
            'departure_at' => $departure,
            'checkin_opens_at' => $departure->copy()->subHours(3),
            'checkin_closes_at' => $departure->copy()->subMinutes(45),
        ];
    }
}
