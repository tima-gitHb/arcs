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

        BaggageAllowance::create(['cabin_class' => CabinClass::Economy, 'pieces' => 1, 'max_kg_per_bag' => 23.0]);
        BaggageAllowance::create(['cabin_class' => CabinClass::Business, 'pieces' => 2, 'max_kg_per_bag' => 32.0]);
    }
}
