<?php

namespace Tests\Feature\Gate;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\BoardingPassService;
use App\Services\JourneyService;
use App\Services\StatusService;
use App\Support\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * G9 / CR-16 — a no-show cancels every later leg of the same booking (multi-city).
 * Builds on G7: StatusService::closeBoarding() → cancelLaterCoupons() by journey sequence.
 */
class G9NoShowLaterLegsTest extends TestCase
{
    use RefreshDatabase;

    private StatusService $statuses;

    private User $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->statuses = app(StatusService::class);
        $this->gate = User::factory()->create(['role' => Role::Gate, 'airport_code' => 'BEY']);
    }

    public function test_no_show_on_leg_2_cancels_leg_3_and_leaves_leg_1_boarded(): void
    {
        [$leg1, $leg2, $leg3] = $this->threeLegs();
        $passenger = $this->bookThree($leg1, $leg2, $leg3);
        $this->checkIn($passenger);

        $this->statuses->setFlightStatus($leg1, FlightStatus::Boarding, $this->gate);
        $this->statuses->board($passenger, $this->gate, $leg1);
        $this->statuses->closeBoarding($leg1, $this->gate);

        $this->assertSame(
            [PassengerStatus::Boarded->value, PassengerStatus::Booked->value, PassengerStatus::Booked->value],
            $this->statusesOf($passenger),
        );

        $this->travelTo(Carbon::parse('2026-11-17 06:00', 'UTC'));
        $this->checkInLeg($passenger, 2, 'CAI');
        $this->openBoarding($leg2);
        $this->statuses->closeBoarding($leg2, $this->gate);

        $coupons = $this->coupons($passenger);
        $this->assertSame(PassengerStatus::Boarded, $coupons[0]->status);
        $this->assertSame(PassengerStatus::NoShow, $coupons[1]->status);
        $this->assertSame(PassengerStatus::Cancelled, $coupons[2]->status);

        $cancelled = AuditEntry::query()
            ->where('passenger_id', $passenger->id)
            ->where('field', 'status')
            ->where('new_value', PassengerStatus::Cancelled->value)
            ->get();

        $this->assertCount(1, $cancelled);
        $this->assertSame($coupons[2]->id, $cancelled->sole()->coupon_id);
    }

    public function test_no_show_on_leg_1_cancels_legs_2_and_3(): void
    {
        [$leg1, $leg2, $leg3] = $this->threeLegs();
        $passenger = $this->bookThree($leg1, $leg2, $leg3);
        $this->checkIn($passenger);

        $this->statuses->setFlightStatus($leg1, FlightStatus::Boarding, $this->gate);
        $this->statuses->closeBoarding($leg1, $this->gate);

        $coupons = $this->coupons($passenger);
        $this->assertSame(PassengerStatus::NoShow, $coupons[0]->status);
        $this->assertSame(PassengerStatus::Cancelled, $coupons[1]->status);
        $this->assertSame(PassengerStatus::Cancelled, $coupons[2]->status);

        $cancelled = AuditEntry::query()
            ->where('passenger_id', $passenger->id)
            ->where('field', 'status')
            ->where('new_value', PassengerStatus::Cancelled->value)
            ->orderBy('coupon_id')
            ->get();

        $this->assertCount(2, $cancelled);
        $this->assertEqualsCanonicalizing(
            [$coupons[1]->id, $coupons[2]->id],
            $cancelled->pluck('coupon_id')->all(),
        );
    }

    /**
     * @return array{0: Flight, 1: Flight, 2: Flight}
     */
    private function threeLegs(): array
    {
        // Multi-city legs with gaps (same pattern as B14MultiCityLegsTest).
        $leg1 = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('101'),
            'origin' => 'BEY',
            'destination' => 'DXB',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 12:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $leg2 = Flight::factory()->create([
            'flight_no' => $this->flightNo('102'),
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_at' => Carbon::parse('2026-11-17 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-17 12:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-17 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-17 07:15', 'UTC'),
        ]);
        $leg3 = Flight::factory()->create([
            'flight_no' => $this->flightNo('103'),
            'origin' => 'RUH',
            'destination' => 'AUH',
            'departure_at' => Carbon::parse('2026-11-19 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-19 14:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-19 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-19 07:15', 'UTC'),
        ]);

        return [$leg1, $leg2, $leg3];
    }

    private function bookThree(Flight $leg1, Flight $leg2, Flight $leg3): Passenger
    {
        $reservation = Reservation::factory()->create(['flight_id' => $leg1->id]);
        app(JourneyService::class)->addSegments(
            $reservation,
            Journey::multiCity([[$leg1], [$leg2], [$leg3]]),
        );

        $passenger = Passenger::factory()->for($reservation)->create();
        TravelDocument::factory()->for($passenger)->create(['issuing_country' => 'LB']);

        foreach ($passenger->coupons as $coupon) {
            $this->seatCoupon($coupon);
        }

        return $passenger->fresh();
    }

    private function checkIn(Passenger $passenger): void
    {
        // C23: check-in is one leg at a time. G9 needs leg 1 CheckedIn (and later legs still Booked
        // until boarded/no-show), so only complete leg 1 here. Leg 2 is checked in when a test needs it.
        $checkin = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']);
        app(BoardingPassService::class)->completeCheckIn($passenger, $checkin, null, null, 1);
    }

    private function checkInLeg(Passenger $passenger, int $leg, string $station): void
    {
        $checkin = User::factory()->create(['role' => Role::Checkin, 'airport_code' => $station]);
        $flight = app(JourneyService::class)->flightsOfLeg($passenger->reservation, $leg)->first();
        if ($flight !== null && $flight->status === FlightStatus::Scheduled) {
            $this->statuses->setFlightStatus($flight, FlightStatus::CheckinOpen, $this->gate);
        }
        app(BoardingPassService::class)->completeCheckIn($passenger, $checkin, null, null, $leg);
    }

    private function openBoarding(Flight $flight): void
    {
        $flight = $flight->fresh();
        if ($flight->status === FlightStatus::Scheduled) {
            $this->statuses->setFlightStatus($flight, FlightStatus::CheckinOpen, $this->gate);
            $flight = $flight->fresh();
        }
        if ($flight->status === FlightStatus::CheckinOpen) {
            $this->statuses->setFlightStatus($flight, FlightStatus::Boarding, $this->gate);
        }
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

    /**
     * @return list<string>
     */
    private function statusesOf(Passenger $passenger): array
    {
        return $passenger->coupons()->get()->map(fn (Coupon $coupon) => $coupon->status->value)->all();
    }
}
