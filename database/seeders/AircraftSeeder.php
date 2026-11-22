<?php

namespace Database\Seeders;

use App\Services\AircraftService;
use Illuminate\Database\Seeder;

/**
 * Six Arcs Air aircraft (Lebanese registrations), 6-across A–F.
 */
class AircraftSeeder extends Seeder
{
    public function run(AircraftService $aircraft): void
    {
        $fleet = [
            ['registration' => 'OD-ARA', 'model' => 'A320neo', 'rows' => 25, 'seats_per_row' => 6, 'business_rows' => 2, 'premium_rows' => 3],
            ['registration' => 'OD-ARB', 'model' => 'A320neo', 'rows' => 25, 'seats_per_row' => 6, 'business_rows' => 2, 'premium_rows' => 3],
            ['registration' => 'OD-ARC', 'model' => 'A321neo', 'rows' => 31, 'seats_per_row' => 6, 'business_rows' => 3, 'premium_rows' => 4],
            ['registration' => 'OD-ARD', 'model' => 'A321neo', 'rows' => 31, 'seats_per_row' => 6, 'business_rows' => 3, 'premium_rows' => 4],
            ['registration' => 'OD-ARE', 'model' => 'A320neo', 'rows' => 27, 'seats_per_row' => 6, 'business_rows' => 0, 'premium_rows' => 4],
            ['registration' => 'OD-ARF', 'model' => 'A321neo', 'rows' => 30, 'seats_per_row' => 6, 'business_rows' => 4, 'premium_rows' => 4],
        ];

        foreach ($fleet as $spec) {
            $aircraft->create($spec);
        }
    }
}
