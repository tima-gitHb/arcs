<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\Role;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\FlightSchedule;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Services\GenerateFlightsFromSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Sprint 4 Feature 1 — weekly schedules: generate, edit sync, cancel date, validation, audit.
 */
class WeeklyScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Aircraft $aircraft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-03-02 10:00:00', 'UTC'));
        $this->aircraft = Aircraft::factory()->create(['registration' => 'OD-SCH']);
    }

    public function test_mon_wed_fri_for_four_weeks_creates_twelve_and_rerun_skips(): void
    {
        $manager = $this->actingAsRole(Role::Manager);
        $schedule = $this->makeSchedule([
            'days_of_week' => [1, 3, 5],
            'valid_from' => '2026-03-02',
            'valid_to' => '2026-04-30',
        ]);

        $from = Carbon::parse('2026-03-02', 'Asia/Beirut')->startOfDay();
        $to = $from->copy()->addWeeks(4)->subDay(); // inclusive 4 weeks of Mon/Wed/Fri = 12

        $generator = app(GenerateFlightsFromSchedule::class);
        $first = $generator->generate($schedule, $from, $to, $manager);

        $this->assertSame(12, $first['created']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(12, Flight::query()->where('flight_schedule_id', $schedule->id)->count());

        $second = $generator->generate($schedule, $from, $to, $manager);
        $this->assertSame(0, $second['created']);
        $this->assertSame(12, $second['skipped']);
        $this->assertSame(12, Flight::query()->where('flight_schedule_id', $schedule->id)->count());
    }

    public function test_aircraft_clash_date_is_not_created_and_is_reported(): void
    {
        $manager = $this->actingAsRole(Role::Manager);
        $schedule = $this->makeSchedule([
            'flight_no' => $this->flightNo('501'),
            'days_of_week' => [1], // Mondays
            'departure_local_time' => '10:00:00',
            'block_minutes' => 120,
            'valid_from' => '2026-03-02',
            'valid_to' => '2026-03-16',
        ]);

        // Existing flight on the same aircraft overlapping Monday 9 Mar 2026 10:00 Beirut.
        $clashLocal = Carbon::parse('2026-03-09 10:00', 'Asia/Beirut');
        Flight::factory()->create([
            'flight_no' => $this->flightNo('999'),
            'aircraft_id' => $this->aircraft->id,
            'origin' => 'BEY',
            'destination' => 'CAI',
            'departure_at' => $clashLocal->copy()->utc(),
            'arrives_at' => $clashLocal->copy()->addHours(2)->utc(),
            'checkin_opens_at' => $clashLocal->copy()->subHours(3)->utc(),
            'checkin_closes_at' => $clashLocal->copy()->subMinutes(45)->utc(),
        ]);

        $result = app(GenerateFlightsFromSchedule::class)->generate(
            $schedule,
            Carbon::parse('2026-03-02', 'Asia/Beirut'),
            Carbon::parse('2026-03-16', 'Asia/Beirut'),
            $manager,
        );

        $this->assertGreaterThanOrEqual(1, $result['clashes']);
        $this->assertContains('2026-03-09', array_column($result['clash_dates'], 'date'));
        $this->assertSame(
            $this->flightNo('999'),
            collect($result['clash_dates'])->firstWhere('date', '2026-03-09')['with'],
        );
        $this->assertFalse(
            Flight::query()->where('flight_no', $this->flightNo('501'))
                ->get()
                ->contains(fn (Flight $f) => $f->departureLocal()->toDateString() === '2026-03-09'),
        );
    }

    public function test_local_departure_clock_kept_across_dst_change(): void
    {
        // Lebanon DST starts last Sunday of March 2026 (29 Mar). Schedule spans both sides.
        $schedule = $this->makeSchedule([
            'days_of_week' => [7], // Sundays
            'departure_local_time' => '09:40:00',
            'block_minutes' => 120,
            'valid_from' => '2026-03-22',
            'valid_to' => '2026-04-12',
        ]);

        app(GenerateFlightsFromSchedule::class)->generate(
            $schedule,
            Carbon::parse('2026-03-22', 'Asia/Beirut'),
            Carbon::parse('2026-04-12', 'Asia/Beirut'),
        );

        $flights = Flight::query()
            ->where('flight_schedule_id', $schedule->id)
            ->orderBy('departure_at')
            ->get();

        $this->assertTrue($flights->count() >= 2);

        foreach ($flights as $flight) {
            $local = $flight->departure_at->copy()->timezone(Airport::timezoneOf('BEY'));
            $this->assertSame('09:40', $local->format('H:i'), 'Local clock must stay 09:40 on '.$local->toDateString());
        }

        $before = $flights->first(fn (Flight $f) => $f->departureLocal()->toDateString() === '2026-03-22');
        $after = $flights->first(fn (Flight $f) => $f->departureLocal()->toDateString() === '2026-04-05');
        $this->assertNotNull($before);
        $this->assertNotNull($after);
        // UTC offset shifts when DST starts: winter UTC+2 → summer UTC+3, so UTC clock moves earlier.
        $this->assertSame('07:40', $before->departure_at->copy()->utc()->format('H:i'));
        $this->assertSame('06:40', $after->departure_at->copy()->utc()->format('H:i'));
    }

    public function test_editing_updates_future_unbooked_only_and_lists_booked(): void
    {
        $manager = $this->actingAsRole(Role::Manager);
        $schedule = $this->makeSchedule([
            'days_of_week' => [1, 3, 5],
            'gate' => 'A1',
            'valid_from' => '2026-03-02',
            'valid_to' => '2026-04-30',
        ]);

        $from = Carbon::parse('2026-03-02', 'Asia/Beirut');
        $to = $from->copy()->addWeeks(3);
        app(GenerateFlightsFromSchedule::class)->generate($schedule, $from, $to, $manager);

        $bookedFlight = Flight::query()
            ->where('flight_schedule_id', $schedule->id)
            ->orderBy('departure_at')
            ->skip(1)
            ->first();
        $this->assertNotNull($bookedFlight);

        $reservation = Reservation::factory()->create(['flight_id' => $bookedFlight->id]);
        Passenger::factory()->for($reservation)->create();

        $response = $this->put(route('manager.schedules.update', $schedule), $this->validPayload($schedule, [
            'gate' => 'B9',
            'departure_local_time' => '11:15',
        ]));
        $response->assertRedirect(route('manager.schedules.show', $schedule));
        $this->followRedirects($response)
            ->assertSee('Booked flights left unchanged')
            ->assertSee($bookedFlight->flight_no);

        $bookedFlight->refresh();
        $this->assertSame('A1', $bookedFlight->gate);
        $this->assertSame('09:40', $bookedFlight->departureLocal()->format('H:i'));

        $unbooked = Flight::query()
            ->where('flight_schedule_id', $schedule->id)
            ->where('id', '!=', $bookedFlight->id)
            ->where('departure_at', '>', now())
            ->get();

        $this->assertTrue($unbooked->isNotEmpty());
        foreach ($unbooked as $flight) {
            $this->assertSame('B9', $flight->gate);
            $this->assertSame('11:15', $flight->departureLocal()->format('H:i'));
        }
    }

    public function test_cancel_one_date_cancels_that_flight_only(): void
    {
        $manager = $this->actingAsRole(Role::Manager);
        $schedule = $this->makeSchedule([
            'days_of_week' => [1, 3, 5],
            'valid_from' => '2026-03-02',
            'valid_to' => '2026-03-31',
        ]);

        app(GenerateFlightsFromSchedule::class)->generate(
            $schedule,
            Carbon::parse('2026-03-02', 'Asia/Beirut'),
            Carbon::parse('2026-03-20', 'Asia/Beirut'),
            $manager,
        );

        $target = Flight::query()->where('flight_schedule_id', $schedule->id)->orderBy('departure_at')->first();
        $otherIds = Flight::query()->where('flight_schedule_id', $schedule->id)->where('id', '!=', $target->id)->pluck('id');

        $this->post(route('manager.schedules.cancel-date', [$schedule, $target]))
            ->assertRedirect();

        $this->assertSame(FlightStatus::Cancelled, $target->fresh()->status);
        foreach (Flight::query()->whereIn('id', $otherIds)->get() as $flight) {
            $this->assertNotSame(FlightStatus::Cancelled, $flight->status);
        }
        $this->assertTrue(
            AuditEntry::query()->where('field', 'flight_schedule_cancel_date')->where('flight_id', $target->id)->exists(),
        );
    }

    public function test_validation_errors_for_bad_schedule_input(): void
    {
        $this->actingAsRole(Role::Manager);

        $this->from(route('manager.schedules.create'))
            ->post(route('manager.schedules.store'), $this->validPayload(null, [
                'origin' => 'BEY',
                'destination' => 'BEY',
                'days_of_week' => [],
                'flight_no' => 'XX1',
                'valid_from' => '2026-04-01',
                'valid_to' => '2026-03-01',
            ]))
            ->assertSessionHasErrors(['destination', 'days_of_week', 'flight_no', 'valid_to']);
    }

    public function test_non_managers_get_403_on_schedules(): void
    {
        $schedule = $this->makeSchedule();

        foreach ([Role::Reservation, Role::Checkin, Role::Gate] as $role) {
            $this->actingAsRole($role);
            $this->get(route('manager.schedules.index'))->assertForbidden();
            $this->get(route('manager.schedules.show', $schedule))->assertForbidden();
            $this->post(route('manager.schedules.store'), $this->validPayload())->assertForbidden();
        }
    }

    public function test_create_and_generate_write_audit_lines(): void
    {
        $this->actingAsRole(Role::Manager);

        $this->post(route('manager.schedules.store'), $this->validPayload())
            ->assertRedirect();

        $schedule = FlightSchedule::query()->first();
        $this->assertNotNull($schedule);
        $this->assertTrue(AuditEntry::query()->where('field', 'flight_schedule_created')->exists());

        $this->post(route('manager.schedules.generate', $schedule), [
            'from' => '2026-03-02',
            'to' => '2026-03-27',
        ])->assertRedirect(route('manager.schedules.show', $schedule));

        $this->assertTrue(AuditEntry::query()->where('field', 'flight_schedule_generate')->exists());
        $this->assertTrue(
            Flight::query()->where('flight_schedule_id', $schedule->id)->exists(),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSchedule(array $overrides = []): FlightSchedule
    {
        return FlightSchedule::create(array_merge([
            'flight_no' => $this->flightNo('500'),
            'origin' => 'BEY',
            'destination' => 'CDG',
            'aircraft_id' => $this->aircraft->id,
            'days_of_week' => [1, 3, 5],
            'departure_local_time' => '09:40:00',
            'block_minutes' => 240,
            'gate' => 'A1',
            'checkin_opens_minutes_before' => 180,
            'checkin_closes_minutes_before' => 45,
            'valid_from' => '2026-03-02',
            'valid_to' => '2026-05-31',
            'active' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(?FlightSchedule $schedule = null, array $overrides = []): array
    {
        $base = [
            'flight_no' => $schedule?->flight_no ?? $this->flightNo('510'),
            'origin' => $schedule?->origin ?? 'BEY',
            'destination' => $schedule?->destination ?? 'CDG',
            'aircraft_id' => $schedule?->aircraft_id ?? $this->aircraft->id,
            'days_of_week' => $schedule?->days_of_week ?? [1, 3, 5],
            'departure_local_time' => $schedule ? $schedule->departureHm() : '09:40',
            'block_minutes' => $schedule?->block_minutes ?? 240,
            'gate' => $schedule?->gate ?? 'A1',
            'checkin_opens_minutes_before' => 180,
            'checkin_closes_minutes_before' => 45,
            'valid_from' => optional($schedule?->valid_from)->toDateString() ?? '2026-03-02',
            'valid_to' => optional($schedule?->valid_to)->toDateString() ?? '2026-05-31',
            'active' => '1',
        ];

        return array_merge($base, $overrides);
    }
}
