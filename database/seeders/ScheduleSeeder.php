<?php

namespace Database\Seeders;

use App\Models\Aircraft;
use App\Models\FlightSchedule;
use Illuminate\Database\Seeder;

/**
 * Weekly patterns for the Arcs Air network (Sprint 4 Feature 1).
 * Run `php artisan arcs:generate-flights` after seeding to create dated flights.
 * FlightSeeder still creates its classic demo flights independently.
 */
class ScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $code = (string) config('arcs.airline.code');
        $from = now('Asia/Beirut')->startOfDay();
        $to = $from->copy()->addMonths(3);

        $odAra = Aircraft::query()->where('registration', 'OD-ARA')->value('id')
            ?? Aircraft::query()->orderBy('id')->value('id');
        $odArb = Aircraft::query()->where('registration', 'OD-ARB')->value('id') ?? $odAra;
        $odArc = Aircraft::query()->where('registration', 'OD-ARC')->value('id') ?? $odAra;
        $odArd = Aircraft::query()->where('registration', 'OD-ARD')->value('id') ?? $odAra;

        if ($odAra === null) {
            return;
        }

        $daily = [1, 2, 3, 4, 5, 6, 7];
        $mwfs = [1, 3, 5, 7]; // Mon / Wed / Fri / Sun

        $rows = [
            // BEY–CDG daily pair
            [$code.'211', 'BEY', 'CDG', $odAra, $daily, '08:15', 275, 'A3'],
            [$code.'212', 'CDG', 'BEY', $odAra, $daily, '14:40', 275, 'E12'],
            // BEY–IST daily pair
            [$code.'264', 'BEY', 'IST', $odArb, $daily, '07:30', 115, 'B2'],
            [$code.'265', 'IST', 'BEY', $odArb, $daily, '10:45', 115, 'C4'],
            // BEY–DXB Mon/Wed/Fri/Sun
            [$code.'401', 'BEY', 'DXB', $odArc, $mwfs, '09:40', 195, 'A5'],
            [$code.'402', 'DXB', 'BEY', $odArc, $mwfs, '14:20', 195, 'D8'],
            // BEY–LHR Mon/Wed/Fri/Sun
            [$code.'420', 'BEY', 'LHR', $odArd, $mwfs, '11:00', 305, 'B4'],
            [$code.'421', 'LHR', 'BEY', $odArd, $mwfs, '17:30', 305, 'A10'],
        ];

        foreach ($rows as [$flightNo, $origin, $destination, $aircraftId, $days, $time, $block, $gate]) {
            FlightSchedule::query()->updateOrCreate(
                ['flight_no' => $flightNo, 'origin' => $origin, 'destination' => $destination],
                [
                    'aircraft_id' => $aircraftId,
                    'days_of_week' => $days,
                    'departure_local_time' => $time.':00',
                    'block_minutes' => $block,
                    'gate' => $gate,
                    'checkin_opens_minutes_before' => 180,
                    'checkin_closes_minutes_before' => 45,
                    'valid_from' => $from->toDateString(),
                    'valid_to' => $to->toDateString(),
                    'active' => true,
                ],
            );
        }
    }
}
