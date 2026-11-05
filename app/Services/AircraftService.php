<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Models\Aircraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an aircraft together with its seats (SR-14).
 */
class AircraftService
{
    /**
     * Rows 1..rows, letters A.. (seats_per_row of them). The first business_rows rows are Business,
     * the next premium_rows are Premium, the rest Economy. Aircraft and seats are saved in one
     * transaction: either all exist, or none.
     *
     * @param  array{registration: string, model: string, rows: int, seats_per_row: int, business_rows: int, premium_rows?: int|null}  $data
     */
    public function create(array $data): Aircraft
    {
        $premiumRows = (int) ($data['premium_rows'] ?? 0);

        if ($data['rows'] < $data['business_rows'] + $premiumRows) {
            throw ValidationException::withMessages([
                'premium_rows' => 'Business and Premium rows cannot be more than the total rows.',
            ]);
        }

        return DB::transaction(function () use ($data, $premiumRows) {
            $aircraft = Aircraft::create([
                'registration' => $data['registration'],
                'model' => $data['model'],
                'premium_rows' => $premiumRows,
            ]);

            $letters = array_slice(range('A', 'J'), 0, $data['seats_per_row']);
            $seats = [];

            for ($row = 1; $row <= $data['rows']; $row++) {
                $class = match (true) {
                    $row <= $data['business_rows'] => CabinClass::Business,
                    $row <= $data['business_rows'] + $premiumRows => CabinClass::Premium,
                    default => CabinClass::Economy,
                };

                foreach ($letters as $letter) {
                    $seats[] = ['seat_no' => $row.$letter, 'cabin_class' => $class];
                }
            }

            $aircraft->seats()->createMany($seats);

            return $aircraft;
        });
    }
}
