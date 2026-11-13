<?php

namespace Database\Seeders;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Models\Fare;
use Illuminate\Database\Seeder;

/**
 * Example route fare (B13b, SR-69): Economy adult BEY→IST is cheaper than the default 250.00.
 * Every other route and class uses the default fares from FareSeeder. Kept apart from FareSeeder,
 * which tests use for the default price list. The Manager edits route fares on card M14.
 */
class RouteFareSeeder extends Seeder
{
    public function run(): void
    {
        Fare::query()->updateOrCreate(
            [
                'origin_airport_code' => 'BEY',
                'destination_airport_code' => 'IST',
                'cabin_class' => CabinClass::Economy,
                'passenger_type' => PassengerType::Adult,
            ],
            ['amount_cents' => 18000, 'currency' => 'USD'],
        );
    }
}
