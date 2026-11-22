<?php

namespace Database\Seeders;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Models\TripFare;
use Illuminate\Database\Seeder;

/**
 * Trip fares (B14): BEY → CDG, LHR, DXB. Economy published prices; one_way from the route fare,
 * round trip ≈ 1.75× one way. Child 75% and infant 10% of the adult trip price.
 */
class TripFareSeeder extends Seeder
{
    /**
     * @var array<string, int> destination => Economy adult one-way cents (matches RouteFareSeeder)
     */
    private const DESTINATIONS = [
        'CDG' => 34000,
        'LHR' => 38000,
        'DXB' => 26000,
    ];

    public function run(): void
    {
        foreach (self::DESTINATIONS as $destination => $oneWayAdult) {
            foreach (PassengerType::cases() as $type) {
                $oneWay = match ($type) {
                    PassengerType::Adult => $oneWayAdult,
                    PassengerType::Child => (int) round($oneWayAdult * 0.75),
                    PassengerType::Infant => (int) round($oneWayAdult * 0.10),
                };

                TripFare::query()->updateOrCreate(
                    [
                        'origin_code' => 'BEY',
                        'destination_code' => $destination,
                        'cabin_class' => CabinClass::Economy,
                        'passenger_type' => $type,
                    ],
                    [
                        'one_way_cents' => $oneWay,
                        'round_trip_cents' => (int) round($oneWay * 1.75),
                    ],
                );
            }
        }
    }
}
