<?php

namespace Tests\Feature;

use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\BoardingPass;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\BagService;
use App\Services\BoardingPassService;
use App\Services\CheckinFlightService;
use App\Services\JourneyService;
use App\Services\StatusService;
use App\Support\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * C14 — through check-in for connecting journeys (SR-66 / SR-67): group and upgrade support.
 */
class C14ThroughCheckinTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->agent = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']);
        $this->actingAs($this->agent);
        $this->seedBookingPrices();
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function connection(int $gap = 90): array
    {
        $first = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('264'), 'origin' => 'BEY', 'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => $this->flightNo('216'), 'origin' => 'IST', 'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 10:00', 'UTC')->addMinutes($gap),
            'arrives_at' => Carbon::parse('2026-11-15 13:45', 'UTC')->addMinutes($gap),
            'checkin_opens_at' => Carbon::parse('2026-11-15 07:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 09:15', 'UTC')->addMinutes($gap),
        ]);

        return [$first, $second];
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function roundTrip(): array
    {
        $out = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('201'), 'origin' => 'BEY', 'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 12:45', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $back = Flight::factory()->create([
            'flight_no' => $this->flightNo('204'), 'origin' => 'CDG', 'destination' => 'BEY',
            'departure_at' => Carbon::parse('2026-11-18 10:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-18 14:15', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-18 07:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-18 09:15', 'UTC'),
        ]);

        return [$out, $back];
    }

    private function book(Journey $journey, CabinClass $cabin = CabinClass::Economy, bool $seat = true): Passenger
    {
        $reservation = Reservation::factory()->create(['flight_id' => $journey->outbound[0]->id]);
        app(JourneyService::class)->addSegments($reservation, $journey);

        $passenger = Passenger::factory()->for($reservation)->create([
            'cabin_class' => $cabin,
            'type' => PassengerType::Adult,
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
        ]);
        TravelDocument::factory()->for($passenger)->create(['issuing_country' => 'LB']);

        if ($seat) {
            foreach ($passenger->coupons as $coupon) {
                $this->seatCoupon($coupon, $cabin);
            }
        }

        return $passenger->fresh();
    }

    private function seatCoupon(Coupon $coupon, CabinClass $cabin = CabinClass::Economy, ?string $seatNo = null): Seat
    {
        // seat_no is string(4): never derive from auto-increment ids (MySQL AI keeps growing across tests).
        if ($seatNo === null) {
            $row = 1;
            while (Seat::query()
                ->where('aircraft_id', $coupon->flight->aircraft_id)
                ->where('seat_no', $row.'A')
                ->exists()) {
                $row++;
            }
            $seatNo = $row.'A';
        }

        $seat = Seat::factory()->create([
            'aircraft_id' => $coupon->flight->aircraft_id,
            'seat_no' => $seatNo,
            'cabin_class' => $cabin,
        ]);
        SeatAssignment::create([
            'flight_id' => $coupon->flight_id,
            'seat_id' => $seat->id,
            'passenger_id' => $coupon->passenger_id,
            'coupon_id' => $coupon->id,
        ]);

        return $seat;
    }

    /**
     * @return list<string>
     */
    private function statuses(Passenger $passenger): array
    {
        return $passenger->coupons()->get()->map(fn (Coupon $c) => $c->status->value)->all();
    }

    private function freeBusinessSeat(Flight $flight, string $seatNo = '1A'): Seat
    {
        return Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => $seatNo,
            'cabin_class' => CabinClass::Business,
        ]);
    }

    public function test_SR66_ME264_connection_check_in_gives_two_passes_with_seats_and_local_times(): void
    {
        [$first, $second] = $this->connection();
        $passenger = $this->book(new Journey([$first, $second]));

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee($this->flightNo('264'))
            ->assertSee($this->flightNo('216'))
            ->assertSee('BEY → IST', false)
            ->assertSee('IST → CDG', false)
            ->assertSee('Connection', false);

        $this->post(route('checkin.passengers.confirm', $passenger))
            ->assertSessionHas('success');

        $passenger = $passenger->fresh();
        $this->assertSame(['CheckedIn', 'CheckedIn'], $this->statuses($passenger));

        $passes = BoardingPass::query()
            ->where('passenger_id', $passenger->id)
            ->whereNull('voided_at')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $passes);
        $this->assertSame($passenger->couponOn($first)->id, $passes[0]->coupon_id);
        $this->assertSame($passenger->couponOn($second)->id, $passes[1]->coupon_id);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee($passes[0]->barcode)
            ->assertSee($passes[1]->barcode)
            ->assertSee($passenger->couponOn($first)->seatAssignment->seat->seat_no)
            ->assertSee($passenger->couponOn($second)->seatAssignment->seat->seat_no);
    }

    public function test_SR67_bags_show_final_destination_CDG(): void
    {
        [$first, $second] = $this->connection();
        $passenger = $this->book(new Journey([$first, $second]));

        app(BagService::class)->add($passenger, 20.0, BagType::Standard, $this->agent);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('CDG');

        $bag = $passenger->bags()->sole();
        $this->assertSame('CDG', $bag->destination);
    }

    public function test_SR66_round_trip_return_stays_Booked_after_outbound_check_in(): void
    {
        [$out, $back] = $this->roundTrip();
        $passenger = $this->book(new Journey([$out], [$back]));

        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');

        $this->assertSame(['CheckedIn', 'Booked'], $this->statuses($passenger->fresh()));
        $this->assertSame(1, $passenger->boardingPasses()->whereNull('voided_at')->count());
    }

    public function test_SR66_group_check_in_with_a_connecting_passenger_checks_in_both_flights(): void
    {
        [$first, $second] = $this->connection();
        $passenger = $this->book(new Journey([$first, $second]));
        $reservation = $passenger->reservation;

        $this->get(route('checkin.reservations.group', $reservation))
            ->assertOk()
            ->assertSee($passenger->last_name)
            ->assertDontSee('Connecting passenger: use single check-in');

        $this->post(route('checkin.reservations.confirm-all', $reservation))
            ->assertRedirect(route('checkin.reservations.group', $reservation))
            ->assertSessionHas('group_results');

        $this->assertSame(['CheckedIn', 'CheckedIn'], $this->statuses($passenger->fresh()));
        $this->assertSame(2, $passenger->boardingPasses()->whereNull('voided_at')->count());
    }

    public function test_SR66_upgrade_on_the_second_flight_changes_only_that_flight_and_charges_once(): void
    {
        [$first, $second] = $this->connection();
        $passenger = $this->book(new Journey([$first, $second]));
        app(BoardingPassService::class)->completeCheckIn($passenger, $this->agent);

        $oldFirstSeat = $passenger->couponOn($first)->seatAssignment->seat->seat_no;
        $business = $this->freeBusinessSeat($second, '2B');

        $this->get(route('checkin.passengers.upgrade', [$passenger, 'flight' => $second->id]))
            ->assertOk()
            ->assertSee($this->flightNo('216'))
            ->assertDontSee('Upgrade is not available for connecting journeys yet');

        $this->post(route('checkin.passengers.upgrade.store', $passenger), [
            'cabin_class' => CabinClass::Business->value,
            'seat_id' => $business->id,
            'flight_id' => $second->id,
            ...$this->paidBookingFields(),
        ])->assertSessionHas('success');

        $passenger = $passenger->fresh();
        $this->assertSame(CabinClass::Business, $passenger->cabin_class);
        $this->assertSame($oldFirstSeat, $passenger->couponOn($first)->seatAssignment->seat->seat_no);
        $this->assertSame('2B', $passenger->couponOn($second)->fresh()->seatAssignment->seat->seat_no);

        $payments = Payment::query()->where('passenger_id', $passenger->id)->where('kind', PaymentKind::Upgrade)->get();
        $this->assertCount(1, $payments);
        $this->assertSame(PaymentStatus::Succeeded, $payments->first()->status);
    }

    public function test_SR66_reprinting_one_pass_voids_only_that_pass(): void
    {
        [$first, $second] = $this->connection();
        $passenger = $this->book(new Journey([$first, $second]));
        app(BoardingPassService::class)->completeCheckIn($passenger, $this->agent);

        $firstPass = $passenger->couponOn($first)->boardingPasses()->whereNull('voided_at')->sole();
        $secondPass = $passenger->couponOn($second)->boardingPasses()->whereNull('voided_at')->sole();

        $this->post(route('checkin.boarding-pass.reprint', $passenger), ['flight_id' => $first->id])
            ->assertSessionHas('success');

        $this->assertNotNull($firstPass->fresh()->voided_at);
        $this->assertNull($secondPass->fresh()->voided_at);
        $this->assertSame(1, $passenger->couponOn($first)->boardingPasses()->whereNull('voided_at')->count());
        $this->assertSame(1, $passenger->couponOn($second)->boardingPasses()->whereNull('voided_at')->count());
    }

    public function test_SR66_one_flight_passengers_unchanged(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $this->flightNo('301'), 'origin' => 'BEY', 'destination' => 'DXB',
        ]);
        $reservation = Reservation::factory()->for($flight)->create();
        $passenger = Passenger::factory()->for($reservation)->create(['cabin_class' => CabinClass::Economy]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
        $this->assertSame(1, $passenger->boardingPasses()->whereNull('voided_at')->count());

        $this->get(route('checkin.reservations.group', $reservation))->assertOk();
    }

    public function test_SR_C13_flight_page_counts_a_connecting_passenger_once_per_flight(): void
    {
        [$first, $second] = $this->connection();
        $passenger = $this->book(new Journey([$first, $second]));
        app(StatusService::class)->checkIn($passenger, $this->agent);

        $this->get(route('checkin.flights.show', $first))
            ->assertOk()
            ->assertSee($passenger->reservation->pnr)
            ->assertSee('1 passenger · 1 checked in', false);

        // Second flight does not depart from BEY — station middleware 403 for this agent.
        // Count is still available through the service for that flight.
        $rows = app(CheckinFlightService::class)->reservations($second);
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['passengers']);
        $this->assertSame(1, $rows[0]['checked_in']);
    }

    public function test_SR_gate_and_reservation_users_get_403_on_through_checkin_actions(): void
    {
        [$first, $second] = $this->connection();
        $passenger = $this->book(new Journey([$first, $second]));

        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->get(route('checkin.passengers.show', $passenger))->assertForbidden();
            $this->post(route('checkin.passengers.confirm', $passenger))->assertForbidden();
            $this->get(route('checkin.reservations.group', $passenger->reservation))->assertForbidden();
            $this->get(route('checkin.passengers.upgrade', [$passenger, 'flight' => $second->id]))->assertForbidden();
        }
    }
}
