<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Models\Aircraft;
use Illuminate\Support\Facades\DB;

/**
 * Creates an aircraft together with its seats (SR-14).
 */
class AircraftService
{
    /**
     * Rows 1..rows, letters A.. (seats_per_row of them). The first business_rows rows are Business,
     * the rest Economy. Aircraft and seats are saved in one transaction: either all exist, or none.
     *
     * @param  array{registration: string, model: string, rows: int, seats_per_row: int, business_rows: int}  $data
     */
    public function create(array $data): Aircraft
    {
        return DB::transaction(function () use ($data) {
            $aircraft = Aircraft::create([
                'registration' => $data['registration'],
                'model' => $data['model'],
            ]);

            $letters = array_slice(range('A', 'J'), 0, $data['seats_per_row']);
            $seats = [];

            for ($row = 1; $row <= $data['rows']; $row++) {
                $class = $row <= $data['business_rows'] ? CabinClass::Business : CabinClass::Economy;

                foreach ($letters as $letter) {
                    $seats[] = ['seat_no' => $row.$letter, 'cabin_class' => $class];
                }
            }

            $aircraft->seats()->createMany($seats);

            return $aircraft;
        });
    }
}
