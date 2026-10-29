<?php

namespace Database\Seeders;

use App\Enums\CabinClass;
use App\Models\Aircraft;
use Illuminate\Database\Seeder;

/**
 * OD-MRA, an A320: 30 rows x A–F = 180 seats. Rows 1–5 Business, 6–30 Economy.
 */
class AircraftSeeder extends Seeder
{
    public function run(): void
    {
        $aircraft = Aircraft::create(['registration' => 'OD-MRA', 'model' => 'A320']);

        $seats = [];
        for ($row = 1; $row <= 30; $row++) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $letter) {
                $seats[] = [
                    'seat_no' => $row.$letter,
                    'cabin_class' => $row <= 5 ? CabinClass::Business : CabinClass::Economy,
                ];
            }
        }

        $aircraft->seats()->createMany($seats);
    }
}
