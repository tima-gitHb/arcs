<?php

namespace Database\Seeders;

use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\PassengerType;
use App\Models\Fare;
use App\Models\Fee;
use Illuminate\Database\Seeder;

/**
 * Sample prices in cents, USD. Card M7 lets the Manager change them.
 */
class FareSeeder extends Seeder
{
    public function run(): void
    {
        $fares = [
            [CabinClass::Economy, [25000, 18750, 2500]],
            [CabinClass::Premium, [42000, 31500, 4200]],
            [CabinClass::Business, [75000, 56250, 7500]],
        ];

        foreach ($fares as [$cabin, [$adult, $child, $infant]]) {
            Fare::create(['cabin_class' => $cabin, 'passenger_type' => PassengerType::Adult, 'amount_cents' => $adult]);
            Fare::create(['cabin_class' => $cabin, 'passenger_type' => PassengerType::Child, 'amount_cents' => $child]);
            Fare::create(['cabin_class' => $cabin, 'passenger_type' => PassengerType::Infant, 'amount_cents' => $infant]);
        }

        Fee::create(['code' => FeeCode::ExtraBag, 'amount_cents' => 5000]);
        Fee::create(['code' => FeeCode::ExcessKg, 'amount_cents' => 1000]);
        Fee::create(['code' => FeeCode::SportsEquipment, 'amount_cents' => 7500]);
        Fee::create(['code' => FeeCode::Pet, 'amount_cents' => 6000]);
    }
}
