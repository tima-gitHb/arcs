<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Models\Airport;
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
 * G7 — gate per station, connecting passengers on the manifest, no-show cancels later coupons (SR-81).
 */
class G7GateStationConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $gateBey;

    private StatusService $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->gateBey = User::factory()->create(['role' => Role::Gate, 'airport_code' => 'BEY']);
        $this->statuses = app(StatusService::class);
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function connection(int $gap = 90): array
    {
        $first = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => 'ME264',
            'origin' => 'BEY',
            'destination' => 'IST',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 10:00', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $second = Flight::factory()->create([
            'flight_no' => 'ME216',
            'origin' => 'IST',
            'destination' => 'CDG',
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
            'flight_no' => 'ME201',
            'origin' => 'BEY',
            'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 08:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 12:45', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 05:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 07:15', 'UTC'),
        ]);
        $back = Flight::factory()->create([
            'flight_no' => 'ME204',
            'origin' => 'CDG',
            'destination' => 'BEY',
            'departure_at' => Carbon::parse('2026-11-18 10:00', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-18 14:15', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-18 07:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-18 09:15', 'UTC'),
        ]);

        return [$out, $back];
    }

    private function book(Journey $journey): Passenger
    {
        $reservation = Reservation::factory()->create(['flight_id' => $journey->outbound[0]->id]);
        app(JourneyService::class)->addSegments($reservation, $journey);

        $passenger = Passenger::factory()->for($reservation)->create();
        TravelDocument::factory()->for($passenger)->create(['issuing_country' => 'LB']);

        foreach ($passenger->coupons as $coupon) {
            $this->seatCoupon($coupon);
        }

        return $passenger->fresh();
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
     * @return list<string>
     */
    private function statuses(Passenger $passenger): array
    {
        return $passenger->coupons()->get()->map(fn (Coupon $coupon) => $coupon->status->value)->all();
    }

    public function test_SR81_gate_agent_sees_own_station_flights_only(): void
    {
        $bey = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => 'ME301',
            'origin' => 'BEY',
            'destination' => 'CDG',
        ]);
        Flight::factory()->create([
            'flight_no' => 'ME401',
            'origin' => 'CDG',
            'destination' => 'BEY',
            'departure_at' => now()->addHours(3),
        ]);

        $this->actingAs($this->gateBey);

        $this->get(route('gate.home'))
            ->assertOk()
            ->assertSee('ME301')
            ->assertDontSee('ME401');

        $this->get(route('gate.flights.show', $bey))->assertOk();
    }

    public function test_SR81_opening_another_stations_flight_returns_403(): void
    {
        $cdg = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => 'ME401',
            'origin' => 'CDG',
            'destination' => 'BEY',
        ]);

        $this->actingAs($this->gateBey);

        $this->get(route('gate.flights.show', $cdg))->assertForbidden();
        $this->post(route('gate.flights.close', $cdg))->assertForbidden();
    }

    public function test_SR81_manager_sees_all_stations_flights(): void
    {
        $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => 'ME301',
            'origin' => 'BEY',
            'destination' => 'CDG',
        ]);
        Flight::factory()->create([
            'flight_no' => 'ME401',
            'origin' => 'CDG',
            'destination' => 'BEY',
            'departure_at' => now()->addHours(3),
        ]);

        $this->actingAs(User::factory()->create(['role' => Role::Manager, 'airport_code' => 'BEY']));

        $this->get(route('manager.home'))
            ->assertOk()
            ->assertSee('ME301')
            ->assertSee('ME401');
    }

    public function test_SR81_manifest_marks_connecting_passengers_with_inbound_and_connection_time(): void
    {
        [$first, $second] = $this->connection(gap: 90);
        $passenger = $this->book(new Journey([$first, $second]));

        $checkin = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']);
        app(BoardingPassService::class)->completeCheckIn($passenger, $checkin);

        // Raise IST MCT so the booked 90 min gap becomes "tight" for the badge.
        Airport::query()->where('code', 'IST')->update(['min_connection_minutes' => 120]);

        $gateIst = User::factory()->create(['role' => Role::Gate, 'airport_code' => 'IST']);
        $this->actingAs($gateIst);

        $this->get(route('gate.flights.show', $second))
            ->assertOk()
            ->assertSee($passenger->last_name)
            ->assertSee('Connecting')
            ->assertSee('ME264')
            ->assertSee('1 h 30 min')
            ->assertSee('Tight connection');
    }

    public function test_SR81_no_show_marks_coupon_and_cancels_the_return(): void
    {
        [$out, $back] = $this->roundTrip();
        $passenger = $this->book(new Journey([$out], [$back]));

        $checkin = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']);
        app(BoardingPassService::class)->completeCheckIn($passenger, $checkin);

        $this->actingAs($this->gateBey);
        $this->statuses->setFlightStatus($out, FlightStatus::Boarding, $this->gateBey);
        $this->statuses->closeBoarding($out, $this->gateBey);

        $this->assertSame(['NoShow', 'Cancelled'], $this->statuses($passenger));

        $this->assertTrue(
            AuditEntry::query()
                ->where('passenger_id', $passenger->id)
                ->where('field', 'status')
                ->where('new_value', PassengerStatus::NoShow->value)
                ->exists()
        );
        $this->assertTrue(
            AuditEntry::query()
                ->where('passenger_id', $passenger->id)
                ->where('field', 'status')
                ->where('new_value', PassengerStatus::Cancelled->value)
                ->exists()
        );
    }

    public function test_SR81_boarded_passenger_is_not_touched_on_close(): void
    {
        [$out, $back] = $this->roundTrip();
        $passenger = $this->book(new Journey([$out], [$back]));

        $checkin = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']);
        app(BoardingPassService::class)->completeCheckIn($passenger, $checkin);

        $this->actingAs($this->gateBey);
        $this->statuses->setFlightStatus($out, FlightStatus::Boarding, $this->gateBey);
        $this->statuses->board($passenger, $this->gateBey, $out);
        $this->statuses->closeBoarding($out, $this->gateBey);

        $this->assertSame(['Boarded', 'Booked'], $this->statuses($passenger));
    }

    public function test_SR81_closing_twice_is_idempotent(): void
    {
        [$out, $back] = $this->roundTrip();
        $passenger = $this->book(new Journey([$out], [$back]));

        $checkin = User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']);
        app(BoardingPassService::class)->completeCheckIn($passenger, $checkin);

        $this->actingAs($this->gateBey);
        $this->statuses->setFlightStatus($out, FlightStatus::Boarding, $this->gateBey);
        $this->statuses->closeBoarding($out, $this->gateBey);

        $auditsAfterFirst = AuditEntry::query()
            ->where('passenger_id', $passenger->id)
            ->where('field', 'status')
            ->count();

        $this->from(route('gate.flights.show', $out))
            ->post(route('gate.flights.close', $out))
            ->assertSessionHas('error', 'Boarding is already closed for this flight.');

        $this->assertSame(['NoShow', 'Cancelled'], $this->statuses($passenger));
        $this->assertSame(
            $auditsAfterFirst,
            AuditEntry::query()
                ->where('passenger_id', $passenger->id)
                ->where('field', 'status')
                ->count()
        );
    }
}
