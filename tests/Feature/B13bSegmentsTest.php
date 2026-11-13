<?php

namespace Tests\Feature;

use App\Enums\BagType;
use App\Enums\CabinClass;
use App\Enums\EntryStatus;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Enums\SegmentDirection;
use App\Exceptions\InvalidJourney;
use App\Exceptions\InvalidStatusChange;
use App\Models\Aircraft;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\AllowanceService;
use App\Services\BagService;
use App\Services\BoardingPassService;
use App\Services\EntryRequirementChecker;
use App\Services\FareService;
use App\Services\JourneyService;
use App\Services\ManifestService;
use App\Services\StatusService;
use App\Support\Journey;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EntryRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * B13b — a reservation holds several flights (segments); each passenger has one coupon per segment.
 * SR-65 journey rules, SR-66 check-in of a direction / boarding per flight, SR-67 bags and allowance,
 * SR-68 entry check on the final destination, SR-69 price per flight.
 *
 * Clock: 15 Nov 2026 06:00 UTC (08:00 Beirut). IST's minimum connection is 60 minutes.
 */
class B13bSegmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->agent = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']);
        $this->gate = User::factory()->create(['role' => Role::Gate, 'airport_code' => 'BEY']);
    }

    /**
     * ME264 BEY→IST (check-in open now, departs 08:00 UTC, lands 10:00) and ME216 IST→CDG,
     * departing $gap minutes after ME264 lands.
     *
     * @return array{0: Flight, 1: Flight}
     */
    private function connection(int $gap): array
    {
        $first = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => 'ME264', 'origin' => 'BEY', 'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => 'ME216', 'origin' => 'IST', 'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 10:00', 'UTC')->addMinutes($gap),
            'arrives_at' => Carbon::parse('2026-11-15 13:45', 'UTC')->addMinutes($gap),
            'checkin_opens_at' => Carbon::parse('2026-11-15 07:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 09:15', 'UTC')->addMinutes($gap),
        ]);

        return [$first, $second];
    }

    /**
     * ME201 BEY→CDG today (check-in open) and ME204 CDG→BEY in 3 days.
     *
     * @return array{0: Flight, 1: Flight}
     */
    private function roundTrip(): array
    {
        $out = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => 'ME201', 'origin' => 'BEY', 'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 12:45', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $back = Flight::factory()->create([
            'flight_no' => 'ME204', 'origin' => 'CDG', 'destination' => 'BEY',
            'departure_at' => Carbon::parse('2026-11-18 10:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-18 14:15', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-18 07:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-18 09:15', 'UTC'),
        ]);

        return [$out, $back];
    }

    /**
     * A reservation on the journey with one Lebanese adult (booked class $cabin), a seat on every flight.
     */
    private function book(Journey $journey, CabinClass $cabin = CabinClass::Economy, bool $seat = true): Passenger
    {
        $reservation = Reservation::factory()->create(['flight_id' => $journey->outbound[0]->id]);
        app(JourneyService::class)->addSegments($reservation, $journey);

        $passenger = Passenger::factory()->for($reservation)->create(['cabin_class' => $cabin]);
        TravelDocument::factory()->for($passenger)->create(['issuing_country' => 'LB']);

        if ($seat) {
            foreach ($passenger->coupons as $coupon) {
                $this->seatCoupon($coupon);
            }
        }

        return $passenger->fresh();
    }

    private function seatCoupon(Coupon $coupon, CabinClass $cabin = CabinClass::Economy): void
    {
        $seat = Seat::factory()->create([
            'aircraft_id' => $coupon->flight->aircraft_id,
            'seat_no' => (10 + $coupon->id).'A',
            'cabin_class' => $cabin,
        ]);
        SeatAssignment::create([
            'flight_id' => $coupon->flight_id, 'seat_id' => $seat->id,
            'passenger_id' => $coupon->passenger_id, 'coupon_id' => $coupon->id,
        ]);
    }

    /**
     * @return list<string> coupon statuses in journey order
     */
    private function statuses(Passenger $passenger): array
    {
        return $passenger->coupons()->get()->map(fn (Coupon $coupon) => $coupon->status->value)->all();
    }

    // --- SR-65 journey rules ------------------------------------------------------------------

    public function test_SR65_a_30_minute_connection_at_IST_is_refused(): void
    {
        [$first, $second] = $this->connection(gap: 30);

        $this->expectException(InvalidJourney::class);
        $this->expectExceptionMessage('Connection at IST is 30 min: at least 60 min needed.');

        app(JourneyService::class)->validate(new Journey([$first, $second]));
    }

    public function test_SR65_a_90_minute_connection_at_IST_is_accepted(): void
    {
        [$first, $second] = $this->connection(gap: 90);

        $passenger = $this->book(new Journey([$first, $second]));

        $segments = $passenger->reservation->segments;
        $this->assertSame([$first->id, $second->id], $segments->pluck('flight_id')->all());
        $this->assertSame([1, 2], $segments->pluck('sequence')->all());
        $this->assertSame([SegmentDirection::Outbound, SegmentDirection::Outbound], $segments->pluck('direction')->all());
        $this->assertSame(['Booked', 'Booked'], $this->statuses($passenger));
        $this->assertSame($first->id, $passenger->reservation->flight_id);
    }

    public function test_SR65_segments_must_connect(): void
    {
        [$first] = $this->connection(gap: 90);
        $fromDubai = Flight::factory()->create([
            'flight_no' => 'ME431', 'origin' => 'DXB', 'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 13:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 20:00', 'UTC'),
        ]);

        $this->expectException(InvalidJourney::class);
        $this->expectExceptionMessage('ME431 departs from DXB, but ME264 lands at IST.');

        app(JourneyService::class)->validate(new Journey([$first, $fromDubai]));
    }

    public function test_SR65_return_departs_after_outbound_arrives(): void
    {
        [$out] = $this->roundTrip();
        $tooEarly = Flight::factory()->create([
            'flight_no' => 'ME212', 'origin' => 'CDG', 'destination' => 'BEY',
            'departure_at' => Carbon::parse('2026-11-15 12:00', 'UTC'), // ME201 lands at 12:45
            'arrives_at' => Carbon::parse('2026-11-15 16:15', 'UTC'),
        ]);

        $this->expectException(InvalidJourney::class);
        $this->expectExceptionMessage('The return ME212 departs before the outbound lands at CDG.');

        app(JourneyService::class)->validate(new Journey([$out], [$tooEarly]));
    }

    public function test_SR65_a_flight_without_an_arrival_time_cannot_be_followed(): void
    {
        [$first, $second] = $this->connection(gap: 90);
        $first->forceFill(['arrives_at' => null])->save();

        $this->expectException(InvalidJourney::class);
        $this->expectExceptionMessage('Set the arrival time of ME264 first.');

        app(JourneyService::class)->validate(new Journey([$first, $second]));
    }

    // --- SR-66 check-in of a direction, boarding per flight ----------------------------------

    public function test_SR66_check_in_at_BEY_checks_in_both_outbound_coupons(): void
    {
        $passenger = $this->book(new Journey($this->connection(gap: 90)));

        $pass = app(BoardingPassService::class)->completeCheckIn($passenger, $this->agent);

        $this->assertSame(['CheckedIn', 'CheckedIn'], $this->statuses($passenger));
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
        // One boarding pass per flight; the returned one is the first flight's.
        $this->assertSame(2, $passenger->boardingPasses()->whereNull('voided_at')->count());
        $this->assertSame($passenger->coupons()->first()->id, $pass->coupon_id);
        // One audit row per coupon, each on its own flight.
        $this->assertSame(2, $passenger->auditEntries()->where('field', 'status')->where('new_value', 'CheckedIn')->count());
    }

    public function test_SR66_a_seat_is_needed_on_every_flight_of_the_direction(): void
    {
        $passenger = $this->book(new Journey($this->connection(gap: 90)), seat: false);
        $this->seatCoupon($passenger->coupons()->first());

        try {
            app(StatusService::class)->checkIn($passenger, $this->agent);
            $this->fail('Check-in without a seat on ME216 must be refused.');
        } catch (InvalidStatusChange $e) {
            $this->assertSame('Seat required on ME216', $e->getMessage());
        }

        $this->assertSame(['Booked', 'Booked'], $this->statuses($passenger));
    }

    public function test_SR66_boarding_at_BEY_boards_only_that_flights_coupon(): void
    {
        [$first, $second] = $this->connection(gap: 90);
        $passenger = $this->book(new Journey([$first, $second]));
        app(StatusService::class)->checkIn($passenger, $this->agent);
        app(StatusService::class)->setFlightStatus($first, FlightStatus::Boarding, $this->gate);

        app(StatusService::class)->board($passenger, $this->gate, $first);

        $this->assertSame(['Boarded', 'CheckedIn'], $this->statuses($passenger));
    }

    public function test_SR66_the_connecting_flights_manifest_lists_the_passenger_with_that_flights_status(): void
    {
        [$first, $second] = $this->connection(gap: 90);
        $passenger = $this->book(new Journey([$first, $second]));
        app(StatusService::class)->checkIn($passenger, $this->agent);
        app(StatusService::class)->setFlightStatus($first, FlightStatus::Boarding, $this->gate);
        app(StatusService::class)->board($passenger, $this->gate, $first);

        $manifest = app(ManifestService::class);
        $onSecond = $manifest->passengers($second);

        $this->assertSame([$passenger->id], $onSecond->pluck('id')->all());
        $this->assertSame(PassengerStatus::CheckedIn, $onSecond->first()->status);
        $this->assertSame(['checked_in' => 1, 'boarded' => 0, 'missing' => 1], $manifest->counts($second));
        $this->assertSame(['checked_in' => 1, 'boarded' => 1, 'missing' => 0], $manifest->counts($first));
    }

    public function test_SR66_round_trip_return_stays_booked_after_outbound_is_boarded(): void
    {
        [$out, $back] = $this->roundTrip();
        $passenger = $this->book(new Journey([$out], [$back]));

        app(BoardingPassService::class)->completeCheckIn($passenger, $this->agent);
        app(StatusService::class)->setFlightStatus($out, FlightStatus::Boarding, $this->gate);
        app(StatusService::class)->board($passenger, $this->gate, $out);

        $this->assertSame(['Boarded', 'Booked'], $this->statuses($passenger));
        $this->assertSame(1, $passenger->boardingPasses()->count());
    }

    public function test_SR66_paris_staff_open_the_return_of_a_beirut_booking(): void
    {
        [$out, $back] = $this->roundTrip();
        $passenger = $this->book(new Journey([$out], [$back]));

        $this->actingAs(User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'CDG']));
        $this->get(route('checkin.passengers.show', $passenger))->assertOk();

        $this->actingAs(User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'DXB']));
        $this->get(route('checkin.passengers.show', $passenger))->assertForbidden();
    }

    public function test_SR66_the_check_in_screen_takes_a_seat_on_each_flight_and_checks_in_both(): void
    {
        [$first, $second] = $this->connection(gap: 90);
        $passenger = $this->book(new Journey([$first, $second]), seat: false);
        $this->seatCoupon($passenger->coupons()->first());
        $seat = Seat::factory()->create(['aircraft_id' => $second->aircraft_id, 'seat_no' => '20C', 'cabin_class' => CabinClass::Economy]);
        $this->actingAs($this->agent);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Connection to CDG')
            ->assertSee('Seat on ME216');

        $this->get(route('checkin.passengers.show', [$passenger, 'flight' => $second->id]))->assertOk()->assertSee('Seat on ME216');
        $this->post(route('checkin.seats.assign', $passenger), ['seat_id' => $seat->id, 'flight_id' => $second->id])
            ->assertRedirect(route('checkin.passengers.show', [$passenger, 'flight' => $second->id]))
            ->assertSessionHas('success', 'Seat 20C assigned.');

        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');

        $this->assertSame(['CheckedIn', 'CheckedIn'], $this->statuses($passenger));
        $this->assertSame('20C', $passenger->couponOn($second)->seatAssignment->seat->seat_no);
    }

    public function test_SR66_paris_staff_see_the_return_flight_on_the_check_in_screen(): void
    {
        [$out, $back] = $this->roundTrip();
        $passenger = $this->book(new Journey([$out], [$back]));

        $this->actingAs(User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'CDG']));

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('ME204 · CDG → BEY')
            ->assertDontSee('ME201 · BEY → CDG');
        $this->get(route('checkin.home', ['q' => $passenger->reservation->pnr]))->assertOk()->assertSee($passenger->last_name);
    }

    public function test_SR66_the_return_is_checked_in_at_its_own_origin(): void
    {
        [$out, $back] = $this->roundTrip();
        $passenger = $this->book(new Journey([$out], [$back]));
        $paris = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'CDG']);

        $this->assertSame(SegmentDirection::Outbound, app(JourneyService::class)->directionFor($passenger, $this->agent));
        $this->assertSame(SegmentDirection::Return, app(JourneyService::class)->directionFor($passenger, $paris));

        $this->travelTo(Carbon::parse('2026-11-18 08:00', 'UTC'));
        app(StatusService::class)->setFlightStatus($back, FlightStatus::CheckinOpen, $paris);
        app(StatusService::class)->checkIn($passenger, $paris);

        $this->assertSame(['Booked', 'CheckedIn'], $this->statuses($passenger));
    }

    public function test_cancel_later_coupons_cancels_the_rest_of_the_direction_for_card_G7(): void
    {
        [$first, $second] = $this->connection(gap: 90);
        $passenger = $this->book(new Journey([$first, $second]));
        app(StatusService::class)->checkIn($passenger, $this->agent);

        $cancelled = app(StatusService::class)->cancelLaterCoupons($passenger->coupons()->first(), $this->gate);

        $this->assertCount(1, $cancelled);
        $this->assertSame(['CheckedIn', 'Cancelled'], $this->statuses($passenger));
        $this->assertSame(0, SeatAssignment::where('coupon_id', $cancelled->first()->id)->count());
    }

    // --- SR-67 bags and allowance -------------------------------------------------------------

    public function test_SR67_bags_are_tagged_to_the_final_destination(): void
    {
        $this->seedBookingPrices();
        $passenger = $this->book(new Journey($this->connection(gap: 90)));

        $bag = app(BagService::class)->add($passenger, 20, BagType::Standard, $this->agent);

        $this->assertSame('CDG', $bag->destination);
        $this->assertSame(SegmentDirection::Outbound, $bag->direction);
    }

    public function test_SR67_a_connection_uses_the_booked_class_allowance(): void
    {
        $this->seedBookingPrices();
        $allowances = app(AllowanceService::class);

        // Connection: booked Business, sitting in Economy on both flights → Business allowance.
        $connecting = $this->book(new Journey($this->connection(gap: 90)), CabinClass::Business);
        $this->assertSame(CabinClass::Business, $allowances->for($connecting)->cabinClass);

        // One flight: today's rule, the seat's cabin first.
        $single = Passenger::factory()->for(Reservation::factory()->for(Flight::factory()))->create(['cabin_class' => CabinClass::Business]);
        $this->seatCoupon($single->coupons()->first());
        $this->assertSame(CabinClass::Economy, $allowances->for($single->fresh())->cabinClass);
    }

    // --- SR-68 entry check --------------------------------------------------------------------

    public function test_SR68_entry_check_for_BEY_IST_CDG_checks_France_only(): void
    {
        // Fixture: LB → TR visa free (90 days), LB → FR visa required.
        $this->seed(EntryRuleSeeder::class);
        $passenger = $this->book(new Journey($this->connection(gap: 90)));

        $result = app(EntryRequirementChecker::class)->check($passenger);

        // Turkey would be Ok (visa free); France needs a visa the passenger does not hold.
        $this->assertSame(EntryStatus::Missing, $result->status);
        $this->assertStringContainsString('France', $result->message);
        $this->assertStringNotContainsString('Turkey', $result->message);
    }

    // --- SR-69 price per flight ---------------------------------------------------------------

    public function test_SR69_journey_price_is_the_sum_of_each_flights_fare(): void
    {
        $this->seedBookingPrices();
        $fares = app(FareService::class);
        $adult = ['cabin_class' => CabinClass::Economy, 'type' => PassengerType::Adult];

        $oneWay = $fares->quote([$adult]);
        $connection = $fares->quote([$adult, $adult], flights: 2);

        $this->assertSame(25000, $oneWay->totalCents);
        $this->assertSame(2 * 2 * 25000, $connection->totalCents);
        $this->assertSame(4, $connection->lines[0]['quantity']);
    }

    // --- Not for connections yet ----------------------------------------------------------------

    public function test_SR66_group_check_in_refuses_a_connecting_passenger(): void
    {
        $passenger = $this->book(new Journey($this->connection(gap: 90)));
        $reservation = $passenger->reservation;
        $this->actingAs($this->agent);

        $this->get(route('checkin.reservations.group', $reservation))
            ->assertRedirect(route('checkin.home', ['q' => $reservation->pnr]))
            ->assertSessionHas('error', 'Connecting passenger: use single check-in');
        $this->post(route('checkin.reservations.confirm-all', $reservation))
            ->assertSessionHas('error', 'Connecting passenger: use single check-in');

        $this->assertSame(['Booked', 'Booked'], $this->statuses($passenger));
    }

    public function test_SR66_the_upgrade_is_refused_for_a_connecting_journey(): void
    {
        $this->seedBookingPrices();
        $passenger = $this->book(new Journey($this->connection(gap: 90)));
        $this->actingAs($this->agent);

        $this->get(route('checkin.passengers.upgrade', $passenger))
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error', 'Upgrade is not available for connecting journeys yet');
    }

    public function test_SR65_segments_added_explicitly_give_no_duplicate_from_the_model_events(): void
    {
        [$first, $second] = $this->connection(gap: 90);
        $reservation = Reservation::factory()->create(['flight_id' => $first->id]);
        $early = Passenger::factory()->for($reservation)->create(); // before addSegments
        app(JourneyService::class)->addSegments($reservation, new Journey([$first, $second]));
        $late = Passenger::factory()->for($reservation)->create(); // after addSegments

        $this->assertSame(2, $reservation->segments()->count());
        foreach ([$early, $late] as $passenger) {
            $this->assertSame(2, $passenger->coupons()->count());
            $this->assertSame(
                [$first->id, $second->id],
                $passenger->coupons()->get()->pluck('flight_id')->all(),
            );
        }
    }

    // --- Manager's flight form -------------------------------------------------------------------

    public function test_SR65_the_flight_form_takes_the_arrival_in_the_destinations_local_time(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Manager]));
        $form = [
            'flight_no' => 'ME219', 'origin' => 'BEY', 'destination' => 'CDG',
            'aircraft_id' => Aircraft::factory()->create()->id,
            'departure_at' => '2026-11-20T10:00', // Beirut, 08:00 UTC
            'checkin_opens_at' => '2026-11-20T07:00', 'checkin_closes_at' => '2026-11-20T09:15',
        ];

        // Lands 12:45 Paris = 11:45 UTC: 3 h 45 after departure.
        $this->post(route('manager.flights.store'), $form + ['arrives_at' => '2026-11-20T12:45'])->assertSessionHasNoErrors();
        $flight = Flight::where('flight_no', 'ME219')->sole();
        $this->assertSame('2026-11-20 11:45', $flight->arrives_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-20 12:45', $flight->arrivalLocal()->format('Y-m-d H:i'));

        // 09:30 Paris = 08:30 UTC is after 08:00 UTC: accepted although "09:30" < "10:00" as text.
        $this->post(route('manager.flights.store'), ['flight_no' => 'ME221', 'arrives_at' => '2026-11-20T09:30'] + $form)->assertSessionHasNoErrors();

        // 08:45 Paris = 07:45 UTC lands before it leaves: refused.
        $this->post(route('manager.flights.store'), ['flight_no' => 'ME223', 'arrives_at' => '2026-11-20T08:45'] + $form)
            ->assertSessionHasErrors(['arrives_at' => 'The arrival must be after the departure.']);

        // Optional: flights without an arrival time can still be created.
        $this->post(route('manager.flights.store'), ['flight_no' => 'ME225'] + $form)->assertSessionHasNoErrors();
    }

    // --- Seed data ----------------------------------------------------------------------------

    public function test_the_seeder_creates_a_connection_and_a_round_trip(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 08:00', 'Asia/Beirut'));
        $this->seed(DatabaseSeeder::class);

        $routes = fn (Reservation $reservation) => $reservation->segments
            ->map(fn ($segment) => $segment->direction->value.' '.$segment->flight->flight_no)->all();

        $connection = Reservation::whereHas('segments.flight', fn ($flight) => $flight->where('flight_no', 'ME264'))->sole();
        $this->assertSame(['Outbound ME264', 'Outbound ME216'], $routes($connection));
        $this->assertSame(3, $connection->passengers()->count());

        $roundTrip = Reservation::whereHas('segments.flight', fn ($flight) => $flight->where('flight_no', 'ME204'))->sole();
        $this->assertSame(['Outbound ME203', 'Return ME204'], $routes($roundTrip));
        $this->assertSame(2, $roundTrip->passengers()->count());

        // Every seeded flight has an arrival time; every passenger has a coupon per segment.
        $this->assertSame(0, Flight::whereNull('arrives_at')->count());
        $this->assertSame(
            Reservation::withCount('segments')->with('passengers')->get()->sum(fn ($r) => $r->segments_count * $r->passengers->count()),
            Coupon::count(),
        );

        // The connection can be checked in at BEY straight away: both flights, one action.
        $passenger = $connection->passengers()->first();
        app(BoardingPassService::class)->completeCheckIn($passenger, User::where('email', 'checkin@arcs.test')->sole());
        $this->assertSame(['CheckedIn', 'CheckedIn'], $this->statuses($passenger));
    }

    // --- Old single-flight reservations -------------------------------------------------------

    public function test_old_single_flight_reservations_work_as_before(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        // Created by the factories: one outbound segment, one coupon.
        $this->assertSame(1, $passenger->reservation->segments()->count());
        $this->assertSame(1, $passenger->coupons()->count());
        $this->assertSame($passenger->coupons()->first()->id, $passenger->seatAssignment->coupon_id);

        app(StatusService::class)->checkIn($passenger, $this->agent);
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->status);
        $this->assertNotNull($passenger->checked_in_at);

        app(StatusService::class)->setFlightStatus($flight, FlightStatus::Boarding, $this->gate);
        app(StatusService::class)->board($passenger, $this->gate);
        $this->assertSame(PassengerStatus::Boarded, $passenger->fresh()->status);
        $this->assertSame($this->gate->id, $passenger->fresh()->boardedBy->user_id);
    }
}
