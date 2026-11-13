<?php

namespace Database\Seeders;

use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\PassengerType;
use App\Models\Fare;
use App\Models\Fee;
use Illuminate\Database\Seeder;

/**
 * Published fares and baggage fees in cents, USD. Card M7 lets the Manager change them.
 * Idempotent: safe to re-run without duplicating rows.
 */
class FareSeeder extends Seeder
{
    public function run(): void
    {
        // One-way BEY fares (dummy, but in a realistic MEA range).
        $fares = [
            [CabinClass::Economy, PassengerType::Adult, 25000],
            [CabinClass::Economy, PassengerType::Child, 18750],
            [CabinClass::Economy, PassengerType::Infant, 2500],
            [CabinClass::Premium, PassengerType::Adult, 42000],
            [CabinClass::Premium, PassengerType::Child, 31500],
            [CabinClass::Premium, PassengerType::Infant, 4200],
            [CabinClass::Business, PassengerType::Adult, 75000],
            [CabinClass::Business, PassengerType::Child, 56250],
            [CabinClass::Business, PassengerType::Infant, 7500],
        ];

        foreach ($fares as [$cabin, $type, $cents]) {
            Fare::query()->updateOrCreate(
                // The default fare: no route (B13b).
                ['origin_airport_code' => null, 'destination_airport_code' => null, 'cabin_class' => $cabin, 'passenger_type' => $type],
                ['amount_cents' => $cents, 'currency' => 'USD'],
            );
        }

        $fees = [
            [FeeCode::ExtraBag, 5000],
            [FeeCode::ExcessKg, 1000],
            [FeeCode::SportsEquipment, 7500],
            [FeeCode::Pet, 6000],
        ];

        foreach ($fees as [$code, $cents]) {
            Fee::query()->updateOrCreate(
                ['code' => $code],
                ['amount_cents' => $cents, 'currency' => 'USD'],
            );
        }
    }
}
