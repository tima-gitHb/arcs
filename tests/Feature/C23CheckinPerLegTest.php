<?php

namespace Tests\Feature;

use App\Enums\BagType;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Exceptions\InvalidStatusChange;
use App\Models\BoardingPass;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\BagService;
use App\Services\BoardingPassService;
use App\Services\JourneyService;
use App\Services\StatusService;
use App\Support\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * C23 / CR-16 — check-in one multi-city leg at a time.
 */
class C23CheckinPerLegTest extends TestCase
{
    use RefreshDatabase;

    private StatusService $statuses;

    private BoardingPassService $passes;

    private JourneyService $journeys;

    private User $bey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->seedBookingPrices();
        $this->statuses = app(StatusService::class);
        $this->passes = app(BoardingPassService::class);
        $this->journeys = app(JourneyService::class);
        $this->bey = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']);
    }

    public function test_leg_1_check_in_issues_only_leg_1_passes_and_bags_to_leg_1_end(): void
    {
        [$leg1a, $leg1b, $leg2] = $this->connectionThenLaterLeg();
        $passenger = $this->book([[$leg1a, $leg1b], [$leg2]]);

        app(BagService::class)->add($passenger, 12.0, BagType::Standard, $this->bey, null, 1);
        $this->passes->completeCheckIn($passenger, $this->bey, null, null, 1);

        $coupons = $this->coupons($passenger);
        $this->assertSame(PassengerStatus::CheckedIn, $coupons[0]->status);
        $this->assertSame(PassengerStatus::CheckedIn, $coupons[1]->status);
        $this->assertSame(PassengerStatus::Booked, $coupons[2]->status);

        $this->assertCount(2, BoardingPass::query()->where('passenger_id', $passenger->id)->whereNull('voided_at')->get());
        $this->assertSame(
            ['DXB'],
            $passenger->bags()->pluck('destination')->unique()->values()->all(),
        );
    }

    public function test_leg_2_refused_before_its_window_and_before_leg_1_flown(): void
    {
        [$leg1, $leg2] = $this->twoCityLegs();
        $passenger = $this->book([[$leg1], [$leg2]]);

        try {
            $this->passes->completeCheckIn($passenger, $this->bey, null, null, 2);
            $this->fail('Expected leg 2 check-in to be refused.');
        } catch (InvalidStatusChange $exception) {
            $this->assertSame('Earlier leg must be flown or cancelled before checking in this leg.', $exception->getMessage());
        }

        $this->passes->completeCheckIn($passenger, $this->bey, null, null, 1);
        $gate = User::factory()->create(['role' => Role::Gate, 'airport_code' => 'BEY']);
        $this->statuses->setFlightStatus($leg1, FlightStatus::Boarding, $gate);
        $this->statuses->board($passenger, $gate, $leg1);
        $this->statuses->closeBoarding($leg1, $gate);

        // Still before leg 2's check-in window (still 15 Nov).
        $cai = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'CAI']);
        try {
            $this->passes->completeCheckIn($passenger, $cai, null, null, 2);
            $this->fail('Expected leg 2 check-in to be refused while its window is closed.');
        } catch (InvalidStatusChange $exception) {
            $this->assertTrue(
                str_contains($exception->getMessage(), 'Check-in closed')
                || str_contains($exception->getMessage(), 'CheckinOpen')
                || str_contains($exception->getMessage(), 'check-in'),
                $exception->getMessage(),
            );
        }
    }

    public function test_leg_2_allowed_in_its_window_after_leg_1_flown(): void
    {
        [$leg1, $leg2] = $this->twoCityLegs();
        $passenger = $this->book([[$leg1], [$leg2]]);

        $this->passes->completeCheckIn($passenger, $this->bey, null, null, 1);
        $gate = User::factory()->create(['role' => Role::Gate, 'airport_code' => 'BEY']);
        $this->statuses->setFlightStatus($leg1, FlightStatus::Boarding, $gate);
        $this->statuses->board($passenger, $gate, $leg1);
        $this->statuses->closeBoarding($leg1, $gate);

        $this->travelTo(Carbon::parse('2026-11-17 06:00', 'UTC'));
        $this->statuses->setFlightStatus($leg2, FlightStatus::CheckinOpen, $gate);
        $cai = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'CAI']);
        $this->passes->completeCheckIn($passenger, $cai, null, null, 2);

        $coupons = $this->coupons($passenger);
        $this->assertSame(PassengerStatus::Boarded, $coupons[0]->status);
        $this->assertSame(PassengerStatus::CheckedIn, $coupons[1]->status);
    }

    public function test_two_flight_connection_inside_one_leg_still_gets_two_boarding_passes(): void
    {
        [$first, $second] = $this->sameDayConnection();
        $passenger = $this->book([[$first, $second]]);

        $this->passes->completeCheckIn($passenger, $this->bey, null, null, 1);

        $this->assertCount(2, BoardingPass::query()->where('passenger_id', $passenger->id)->whereNull('voided_at')->get());
        $this->assertSame(
            [PassengerStatus::CheckedIn, PassengerStatus::CheckedIn],
            array_map(fn (Coupon $c) => $c->status, $this->coupons($passenger)),
        );
    }

    public function test_checkin_screen_shows_leg_strip_for_multi_city(): void
    {
        [$leg1, $leg2] = $this->twoCityLegs();
        $passenger = $this->book([[$leg1], [$leg2]]);

        $this->actingAs($this->bey)
            ->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('data-leg-strip', false)
            ->assertSee('Leg 1')
            ->assertSee('Leg 2')
            ->assertSee('check-in opens');
    }

    /**
     * @return array{0: Flight, 1: Flight, 2: Flight}
     */
    private function connectionThenLaterLeg(): array
    {
        $first = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('201'),
            'origin' => 'BEY',
            'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => $this->flightNo('202'),
            'origin' => 'IST',
            'destination' => 'DXB',
            'departure_at' => Carbon::parse('2026-11-15 11:30', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 15:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 10:45', 'UTC'),
        ]);
        $later = Flight::factory()->create([
            'flight_no' => $this->flightNo('203'),
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_at' => Carbon::parse('2026-11-17 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-17 11:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-17 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-17 07:15', 'UTC'),
        ]);

        return [$first, $second, $later];
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function twoCityLegs(): array
    {
        $leg1 = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('301'),
            'origin' => 'BEY',
            'destination' => 'DXB',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 12:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $leg2 = Flight::factory()->create([
            'flight_no' => $this->flightNo('302'),
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_at' => Carbon::parse('2026-11-17 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-17 12:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-17 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-17 07:15', 'UTC'),
        ]);

        return [$leg1, $leg2];
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function sameDayConnection(): array
    {
        $first = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('264'),
            'origin' => 'BEY',
            'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => $this->flightNo('216'),
            'origin' => 'IST',
            'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 11:30', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 15:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 10:45', 'UTC'),
        ]);

        return [$first, $second];
    }

    /**
     * @param  list<list<Flight>>  $legs
     */
    private function book(array $legs): Passenger
    {
        $first = $legs[0][0];
        $reservation = Reservation::factory()->create(['flight_id' => $first->id]);
        $this->journeys->addSegments($reservation, Journey::multiCity($legs));

        $passenger = Passenger::factory()->for($reservation)->create();
        TravelDocument::factory()->for($passenger)->create(['issuing_country' => 'LB']);

        foreach ($passenger->coupons as $coupon) {
            $this->seatCoupon($coupon);
        }

        return $passenger->fresh(['coupons', 'reservation.segments.flight']);
    }

    private function seatCoupon(Coupon $coupon): void
    {
        $row = 1;
        while (Seat::query()
            ->where('aircraft_id', $coupon->flight->aircraft_id)
            ->where('seat_no', $row.'A')
            ->exists()) {
            $row++;
        }

        $seat = Seat::factory()->create([
            'aircraft_id' => $coupon->flight->aircraft_id,
            'seat_no' => $row.'A',
        ]);
        SeatAssignment::create([
            'flight_id' => $coupon->flight_id,
            'seat_id' => $seat->id,
            'passenger_id' => $coupon->passenger_id,
            'coupon_id' => $coupon->id,
        ]);
    }

    /**
     * @return list<Coupon>
     */
    private function coupons(Passenger $passenger): array
    {
        return $passenger->coupons()->orderBy('reservation_segment_id')->get()->all();
    }
}
