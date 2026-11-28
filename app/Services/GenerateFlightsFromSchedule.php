<?php

namespace App\Services;

use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\FlightSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Creates dated Scheduled flights from a weekly pattern (Sprint 4 Feature 1).
 */
class GenerateFlightsFromSchedule
{
    public function __construct(
        private FlightScheduleService $schedules,
    ) {}

    /**
     * @return array{
     *   created: int,
     *   skipped: int,
     *   clashes: int,
     *   created_dates: list<string>,
     *   skipped_dates: list<string>,
     *   clash_dates: list<array{date: string, with: string}>
     * }
     */
    public function generate(FlightSchedule $schedule, Carbon $from, Carbon $to, ?User $by = null): array
    {
        $originTz = Airport::timezoneOf($schedule->origin);
        $fromLocal = $from->copy()->timezone($originTz)->startOfDay();
        $toLocal = $to->copy()->timezone($originTz)->startOfDay();
        $validFrom = $schedule->valid_from->copy()->timezone($originTz)->startOfDay();
        $validTo = $schedule->valid_to->copy()->timezone($originTz)->startOfDay();

        if ($fromLocal->lt($validFrom)) {
            $fromLocal = $validFrom->copy();
        }
        if ($toLocal->gt($validTo)) {
            $toLocal = $validTo->copy();
        }

        $created = [];
        $skipped = [];
        $clashes = [];

        for ($day = $fromLocal->copy(); $day->lte($toLocal); $day->addDay()) {
            if (! $schedule->isValidOn($day)) {
                continue;
            }

            $dateLabel = $day->toDateString();
            $times = $this->timesFor($schedule, $day);

            if ($this->flightExistsOnLocalDate($schedule->flight_no, $schedule->origin, $dateLabel)) {
                $skipped[] = $dateLabel;

                continue;
            }

            try {
                $this->schedules->assertNoOverlap([
                    'aircraft_id' => $schedule->aircraft_id,
                    'departure_at' => $times['departure_at'],
                    'arrives_at' => $times['arrives_at'],
                ]);
            } catch (ValidationException $e) {
                $with = $this->clashFlightNo($e) ?? 'another flight';
                $clashes[] = ['date' => $dateLabel, 'with' => $with];

                continue;
            }

            $flight = Flight::create([
                'flight_no' => $schedule->flight_no,
                'origin' => $schedule->origin,
                'destination' => $schedule->destination,
                'aircraft_id' => $schedule->aircraft_id,
                'flight_schedule_id' => $schedule->id,
                'departure_at' => $times['departure_at'],
                'arrives_at' => $times['arrives_at'],
                'gate' => $schedule->gate,
                'checkin_opens_at' => $times['checkin_opens_at'],
                'checkin_closes_at' => $times['checkin_closes_at'],
            ]);

            $created[] = $dateLabel;

            if ($by !== null) {
                AuditEntry::forFlight($flight, 'schedule_generated', null, $schedule->flight_no.' '.$dateLabel, $by);
            }
        }

        if ($by !== null) {
            AuditEntry::create([
                'field' => 'flight_schedule_generate',
                'old_value' => null,
                'new_value' => mb_substr(
                    "{$schedule->flight_no}: created ".count($created)
                    .' · skipped '.count($skipped)
                    .' · clash '.count($clashes),
                    0,
                    255,
                ),
                'user_id' => $by->id,
                'changed_at' => now(),
            ]);
        }

        return [
            'created' => count($created),
            'skipped' => count($skipped),
            'clashes' => count($clashes),
            'created_dates' => $created,
            'skipped_dates' => $skipped,
            'clash_dates' => $clashes,
        ];
    }

    /**
     * Apply schedule edits to future generated flights that have no bookings.
     *
     * @return array{updated: int, booked: Collection<int, Flight>}
     */
    public function syncFutureUnbooked(FlightSchedule $schedule, ?User $by = null): array
    {
        $future = $schedule->flights()
            ->where('departure_at', '>', now())
            ->withCount('coupons')
            ->orderBy('departure_at')
            ->get();

        $booked = $future->filter(fn (Flight $flight) => $flight->coupons_count > 0)->values();
        $updated = 0;

        foreach ($future->filter(fn (Flight $flight) => $flight->coupons_count === 0) as $flight) {
            $localDate = $flight->departureLocal()->startOfDay();
            $times = $this->timesFor($schedule, $localDate);

            try {
                $this->schedules->assertNoOverlap([
                    'aircraft_id' => $schedule->aircraft_id,
                    'departure_at' => $times['departure_at'],
                    'arrives_at' => $times['arrives_at'],
                ], $flight->id);
            } catch (ValidationException) {
                // Leave this date alone when the new pattern clashes; manager handles it.
                continue;
            }

            $before = $flight->flight_no.' '.$flight->departure_at->toIso8601String();
            $flight->forceFill([
                'flight_no' => $schedule->flight_no,
                'origin' => $schedule->origin,
                'destination' => $schedule->destination,
                'aircraft_id' => $schedule->aircraft_id,
                'departure_at' => $times['departure_at'],
                'arrives_at' => $times['arrives_at'],
                'gate' => $schedule->gate,
                'checkin_opens_at' => $times['checkin_opens_at'],
                'checkin_closes_at' => $times['checkin_closes_at'],
            ])->save();
            $updated++;

            if ($by !== null) {
                AuditEntry::forFlight(
                    $flight,
                    'schedule_sync',
                    $before,
                    $schedule->flight_no.' '.$times['departure_at']->toIso8601String(),
                    $by,
                );
            }
        }

        return ['updated' => $updated, 'booked' => $booked];
    }

    /**
     * @return array{departure_at: Carbon, arrives_at: Carbon, checkin_opens_at: Carbon, checkin_closes_at: Carbon}
     */
    public function timesFor(FlightSchedule $schedule, Carbon $localDate): array
    {
        $originTz = Airport::timezoneOf($schedule->origin);
        $hm = $schedule->departureHm();
        $departureLocal = Carbon::parse($localDate->toDateString().' '.$hm, $originTz);
        $departureUtc = $departureLocal->copy()->utc();
        $arrivesUtc = $departureUtc->copy()->addMinutes($schedule->block_minutes);

        return [
            'departure_at' => $departureUtc,
            'arrives_at' => $arrivesUtc,
            'checkin_opens_at' => $departureUtc->copy()->subMinutes($schedule->checkin_opens_minutes_before),
            'checkin_closes_at' => $departureUtc->copy()->subMinutes($schedule->checkin_closes_minutes_before),
        ];
    }

    private function flightExistsOnLocalDate(string $flightNo, string $origin, string $localDate): bool
    {
        return Flight::query()
            ->where('flight_no', $flightNo)
            ->where('origin', $origin)
            ->get()
            ->contains(fn (Flight $flight) => $flight->departureLocal()->toDateString() === $localDate);
    }

    private function clashFlightNo(ValidationException $e): ?string
    {
        $message = $e->errors()['aircraft_id'][0] ?? '';
        if (preg_match('/busy with ([A-Z0-9]+)/', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
