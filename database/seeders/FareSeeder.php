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
        // Network defaults: Economy adult 250; Premium ×1.6; Business ×3.2; child 75%; infant 10%.
        $fares = [
            [CabinClass::Economy, PassengerType::Adult, 25000],
            [CabinClass::Economy, PassengerType::Child, 18750],
            [CabinClass::Economy, PassengerType::Infant, 2500],
            [CabinClass::Premium, PassengerType::Adult, 40000],
            [CabinClass::Premium, PassengerType::Child, 30000],
            [CabinClass::Premium, PassengerType::Infant, 4000],
            [CabinClass::Business, PassengerType::Adult, 80000],
            [CabinClass::Business, PassengerType::Child, 60000],
            [CabinClass::Business, PassengerType::Infant, 8000],
        ];

        foreach ($fares as [$cabin, $type, $cents]) {
            Fare::query()->updateOrCreate(
                // The default fare: no route (B13b).
                ['origin_airport_code' => null, 'destination_airport_code' => null, 'cabin_class' => $cabin, 'passenger_type' => $type],
                ['amount_cents' => $cents, 'currency' => 'USD'],
            );
        }

        // Existing fee codes; amounts kept so pricing tests stay stable.
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
