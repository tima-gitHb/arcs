<?php

namespace Database\Seeders;

use App\Enums\CabinClass;
use App\Models\BaggageAllowance;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Rules the Manager can change later: passport validity and baggage allowances.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        Setting::create(['key' => 'passport_min_months', 'value' => '6']);
        Setting::create(['key' => 'hard_max_kg_per_bag', 'value' => '32']); // safety limit: no bag above it

        BaggageAllowance::create(['cabin_class' => CabinClass::Economy, 'pieces' => 2, 'max_kg_per_bag' => 23.0]);
        BaggageAllowance::create(['cabin_class' => CabinClass::Premium, 'pieces' => 2, 'max_kg_per_bag' => 23.0]);
        BaggageAllowance::create(['cabin_class' => CabinClass::Business, 'pieces' => 2, 'max_kg_per_bag' => 32.0]);
    }
}
