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

        // Rule limits (B14, CR-15). Values only; rules that use them are later cards.
        foreach ([
            'child_alone_min_age' => '16',
            'umnr_min_age' => '5',
            'max_infants_per_adult' => '1',
            'max_passengers_per_booking' => '9',
            'max_bag_kg' => '32',
            'max_aircraft_seats' => '220',
            'min_turnaround_minutes' => '45',
            'max_connection_hours' => '24',
            'max_pets_in_cabin' => '2',
            'max_legs' => '6',
        ] as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

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
