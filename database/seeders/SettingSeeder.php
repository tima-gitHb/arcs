<?php

namespace Database\Seeders;

use App\Enums\CabinClass;
use App\Models\BaggageAllowance;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Rules the Manager can change later: passport validity and baggage allowances.
 * Idempotent: safe to re-run without duplicating rows.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'passport_min_months'],
            ['value' => '6'],
        );
        Setting::query()->updateOrCreate(
            ['key' => 'hard_max_kg_per_bag'],
            ['value' => '32'], // safety limit: no bag above it
        );

        $allowances = [
            [CabinClass::Economy, 2, 23.0],
            [CabinClass::Premium, 2, 23.0],
            [CabinClass::Business, 2, 32.0],
        ];

        foreach ($allowances as [$cabin, $pieces, $kg]) {
            BaggageAllowance::query()->updateOrCreate(
                ['cabin_class' => $cabin],
                ['pieces' => $pieces, 'max_kg_per_bag' => $kg],
            );
        }
    }
}
