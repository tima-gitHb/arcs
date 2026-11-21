<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rule limits (B14, CR-15). Values only; the rules that use them are later cards.
 * Idempotent inserts so re-running seeders stays safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $limits = [
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
        ];

        foreach ($limits as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value],
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'child_alone_min_age',
            'umnr_min_age',
            'max_infants_per_adult',
            'max_passengers_per_booking',
            'max_bag_kg',
            'max_aircraft_seats',
            'min_turnaround_minutes',
            'max_connection_hours',
            'max_pets_in_cabin',
            'max_legs',
        ])->delete();
    }
};
