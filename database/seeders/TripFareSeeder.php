<?php

namespace Database\Seeders;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Models\TripFare;
use Illuminate\Database\Seeder;

/**
 * Example trip fares (B14, CR-13/CR-14). Idempotent.
 */
class TripFareSeeder extends Seeder
{
    public function run(): void
    {
        TripFare::query()->updateOrCreate(
            [
                'origin_code' => 'BEY',
                'destination_code' => 'CDG',
                'cabin_class' => CabinClass::Economy,
                'passenger_type' => PassengerType::Adult,
            ],
            [
                'one_way_cents' => 45000,
                'round_trip_cents' => 80000,
            ],
        );
    }
}
