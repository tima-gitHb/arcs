<?php

namespace Tests\Feature\Manager;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\Role;
use App\Exceptions\InvalidStatusChange;
use App\Models\Aircraft;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * M1 — Flights, aircraft + gate change (SR-14, SR-25, SR-42).
 *
 * Routes these tests expect (added in step 3):
 *   POST  manager.aircraft.store   fields: registration, model, rows, seats_per_row, business_rows
 *   POST  manager.flights.store    fields: flight_no, origin, destination, aircraft_id, departure_at,
 *                                  checkin_opens_at, checkin_closes_at, gate
 *   PATCH manager.flights.status   {flight}  field: status
 *   PATCH manager.flights.gate     {flight}  field: gate
 * A status button is <button name="status" value="CheckinOpen">. Status errors are under the 'status' key.
 */
class FlightTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // flightWithStatus() opens the window around now(); a flight departs today at 12:00.
        $this->travelTo('2026-11-10 10:00:00');

        $this->manager = $this->actingAsRole(Role::Manager);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validAircraft(array $overrides = []): array
    {
        return $overrides + [
            'registration' => 'OD-TST',
            'model' => 'A320',
            'rows' => 30,
            'seats_per_row' => 6,
            'business_rows' => 5,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validFlight(array $overrides = []): array
    {
        return $overrides + [
            'flight_no' => $this->flightNo('205'),
            'origin' => 'BEY',
            'destination' => 'CDG',
            'aircraft_id' => Aircraft::factory()->create()->id,
            'departure_at' => '2026-11-11T14:00',
            'checkin_opens_at' => '2026-11-11T11:00',
            'checkin_closes_at' => '2026-11-11T13:15',
            'gate' => 'B4',
        ];
    }

    // ── Aircraft (SR-14) ─────────────────────────────────────────────────────

    public function test_SR14_aircraft_30x6_with_business_rows_1_to_5_has_180_seats_with_class(): void
    {
        $this->post(route('manager.aircraft.store'), $this->validAircraft())
            ->assertRedirect(route('manager.aircraft.index'));

        $aircraft = Aircraft::where('registration', 'OD-TST')->sole();
        $this->assertSame('A320', $aircraft->model);

        $this->assertSame(180, $aircraft->seats()->count());
        $this->assertSame(30, $aircraft->seats()->where('cabin_class', CabinClass::Business)->count());
        $this->assertSame(150, $aircraft->seats()->where('cabin_class', CabinClass::Economy)->count());

        // The first and last seat of the Business block, and the first and last Economy seats.
        $this->assertSame(CabinClass::Business, $aircraft->seats()->where('seat_no', '1A')->sole()->cabin_class);
        $this->assertSame(CabinClass::Business, $aircraft->seats()->where('seat_no', '5F')->sole()->cabin_class);
        $this->assertSame(CabinClass::Economy, $aircraft->seats()->where('seat_no', '6A')->sole()->cabin_class);
        $this->assertSame(CabinClass::Economy, $aircraft->seats()->where('seat_no', '30F')->sole()->cabin_class);
    }

    public function test_seat_letters_follow_seats_per_row(): void
    {
        $this->post(route('manager.aircraft.store'), $this->validAircraft([
            'registration' => 'OD-SML', 'rows' => 4, 'seats_per_row' => 4, 'business_rows' => 0,
        ]))->assertRedirect();

        $seats = Aircraft::where('registration', 'OD-SML')->sole()->seats()->orderBy('id')->pluck('seat_no');

        $this->assertCount(16, $seats);
        $this->assertSame(['1A', '1B', '1C', '1D', '2A'], $seats->take(5)->all());
        $this->assertSame('4D', $seats->last());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidAircraft(): array
    {
        return [
            'no rows' => [['rows' => 0], 'rows'],
            'too many rows' => [['rows' => 100], 'rows'],
            'no seats per row' => [['seats_per_row' => 0], 'seats_per_row'],
            'too many seats per row' => [['seats_per_row' => 11], 'seats_per_row'],
            'more business rows than rows' => [['rows' => 10, 'business_rows' => 11], 'business_rows'],
            'negative business rows' => [['business_rows' => -1], 'business_rows'],
            'no registration' => [['registration' => ''], 'registration'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidAircraft')]
    public function test_invalid_aircraft_is_rejected(array $overrides, string $errorField): void
    {
        $this->post(route('manager.aircraft.store'), $this->validAircraft($overrides))
            ->assertSessionHasErrors($errorField);

        $this->assertSame(0, Aircraft::count());
        $this->assertDatabaseCount('seats', 0);
    }

    public function test_duplicate_registration_is_rejected(): void
    {
        Aircraft::factory()->create(['registration' => 'OD-TST']);

        $this->post(route('manager.aircraft.store'), $this->validAircraft())
            ->assertSessionHasErrors('registration');

        $this->assertSame(1, Aircraft::count());
        $this->assertDatabaseCount('seats', 0);
    }

    // ── Flights ──────────────────────────────────────────────────────────────

    public function test_flight_is_created_with_valid_data(): void
    {
        $data = $this->validFlight(['flight_no' => strtolower($this->airlineCode()).'205']); // lower case: uppercased before validating

        $response = $this->post(route('manager.flights.store'), $data)
            ->assertSessionHasNoErrors();

        $flight = Flight::sole();
        $response->assertRedirect(route('manager.flights.show', $flight));
        $this->assertSame($this->flightNo('205'), $flight->flight_no);
        $this->assertSame('BEY', $flight->origin);
        $this->assertSame('CDG', $flight->destination);
        $this->assertSame($data['aircraft_id'], $flight->aircraft_id);
        $this->assertSame('B4', $flight->gate);
        $this->assertSame(FlightStatus::Scheduled, $flight->status);
        // Typed in Beirut time (the origin), stored in UTC: Beirut is UTC+2 in November (B13a).
        $this->assertSame('2026-11-11 14:00', $flight->departureLocal()->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 11:00', $flight->atOrigin($flight->checkin_opens_at)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 13:15', $flight->atOrigin($flight->checkin_closes_at)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 12:00', $flight->departure_at->format('Y-m-d H:i'));
    }

    public function test_lowercase_airport_codes_and_gate_are_accepted_and_saved_uppercase(): void
    {
        $this->post(route('manager.flights.store'), $this->validFlight([
            'origin' => 'bey',
            'destination' => 'cdg',
            'gate' => 'b4',
        ]))->assertSessionHasNoErrors();

        $flight = Flight::sole();
        $this->assertSame('BEY', $flight->origin);
        $this->assertSame('CDG', $flight->destination);
        $this->assertSame('B4', $flight->gate);

        // Changing the gate of an existing flight works the same way.
        $this->patch(route('manager.flights.gate', $flight), ['gate' => 'c12'])->assertSessionHasNoErrors();
        $this->assertSame('C12', $flight->fresh()->gate);
    }

    public function test_gate_is_optional_when_creating_a_flight(): void
    {
        $this->post(route('manager.flights.store'), $this->validFlight(['gate' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Flight::sole()->gate);
    }

    public function test_created_flight_is_listed_on_the_page(): void
    {
        $this->post(route('manager.flights.store'), $this->validFlight());

        $this->get(route('manager.flights'))
            ->assertOk()
            ->assertSee($this->flightNo('205'))
            ->assertSee('Scheduled');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFlights(): array
    {
        return [
            'bad origin code' => [['origin' => 'BE1'], 'origin'],
            'origin too long' => [['origin' => 'BEYR'], 'origin'],
            'bad destination code' => [['destination' => 'CD'], 'destination'],
            'same origin and destination' => [['destination' => 'BEY'], 'destination'],
            'check-in closes after departure' => [['checkin_closes_at' => '2026-11-11T14:30'], 'checkin_closes_at'],
            'check-in opens after it closes' => [['checkin_opens_at' => '2026-11-11T13:30'], 'checkin_opens_at'],
            'unknown aircraft' => [['aircraft_id' => 99999], 'aircraft_id'],
            'bad flight number' => [['flight_no' => 'ABC'], 'flight_no'],
            'bad gate' => [['gate' => '3A'], 'gate'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidFlights')]
    public function test_invalid_flight_data_is_rejected(array $overrides, string $errorField): void
    {
        $this->post(route('manager.flights.store'), $this->validFlight($overrides))
            ->assertSessionHasErrors($errorField);

        $this->assertSame(0, Flight::count());
    }

    // ── Status (SR-25) ───────────────────────────────────────────────────────

    public function test_SR25_illegal_status_move_is_refused(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Scheduled);

        $response = $this->from(route('manager.flights.show', ['flight' => $flight, 'tab' => 'status']))
            ->patch(route('manager.flights.status', $flight), ['status' => 'Boarding']);

        $response->assertRedirect(route('manager.flights.show', ['flight' => $flight, 'tab' => 'status']))
            ->assertSessionHasErrors('status');
        $this->assertSame(FlightStatus::Scheduled, $flight->fresh()->status);

        $this->followRedirects($response)->assertSee('Flight cannot go from Scheduled to Boarding');
    }

    public function test_closed_cannot_be_set_from_this_page(): void
    {
        // Boarding -> Closed is closeBoarding(), the gate role's job.
        $flight = $this->flightWithStatus(FlightStatus::Boarding);

        $this->from(route('manager.flights'))
            ->patch(route('manager.flights.status', $flight), ['status' => 'Closed'])
            ->assertSessionHasErrors('status');

        $this->assertSame(FlightStatus::Boarding, $flight->fresh()->status);
    }

    public function test_unknown_status_value_is_rejected(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Scheduled);

        $this->from(route('manager.flights'))
            ->patch(route('manager.flights.status', $flight), ['status' => 'Flying'])
            ->assertSessionHasErrors('status');

        $this->assertSame(FlightStatus::Scheduled, $flight->fresh()->status);
    }

    public function test_allowed_status_move_goes_through_status_service(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Scheduled);

        $this->patch(route('manager.flights.status', $flight), ['status' => 'CheckinOpen'])
            ->assertSessionHasNoErrors();

        $this->assertSame(FlightStatus::CheckinOpen, $flight->fresh()->status);
    }

    public function test_status_change_is_delegated_to_status_service_with_the_logged_in_manager(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Scheduled);

        $this->partialMock(StatusService::class, function ($mock) use ($flight) {
            $mock->shouldReceive('setFlightStatus')
                ->once()
                ->withArgs(fn (Flight $f, FlightStatus $new, User $by) => $f->is($flight)
                    && $new === FlightStatus::Delayed
                    && $by->is($this->manager));
        });

        $this->patch(route('manager.flights.status', $flight), ['status' => 'Delayed'])
            ->assertSessionHasNoErrors();
    }

    public function test_allowed_flight_moves_match_what_set_flight_status_accepts(): void
    {
        $service = app(StatusService::class);
        $starts = [
            FlightStatus::Scheduled, FlightStatus::CheckinOpen, FlightStatus::Boarding, FlightStatus::Closed,
            FlightStatus::Departed, FlightStatus::Delayed, FlightStatus::Cancelled,
        ];

        foreach ($starts as $start) {
            $accepted = [];

            // One fresh flight per target: a successful move would change the flight's status.
            foreach (FlightStatus::cases() as $target) {
                $flight = $this->flightWithStatus($start);

                try {
                    $service->setFlightStatus($flight, $target, $this->manager);
                    $accepted[] = $target->value;
                } catch (InvalidStatusChange) {
                    // refused: not an allowed move
                }
            }

            // fresh(): a flight made by the factory has no status in memory until it is read back.
            $listed = collect($service->allowedFlightMoves($this->flightWithStatus($start)->fresh()))
                ->map(fn (FlightStatus $status) => $status->value)
                ->all();

            sort($accepted);
            sort($listed);

            $this->assertSame($accepted, $listed, "allowed moves from {$start->value}");
        }
    }

    /**
     * @return array<string, array{FlightStatus, list<string>}>
     */
    public static function buttonsPerStatus(): array
    {
        return [
            'Scheduled' => [FlightStatus::Scheduled, ['CheckinOpen', 'Delayed', 'Cancelled']],
            'CheckinOpen' => [FlightStatus::CheckinOpen, ['Boarding', 'Delayed', 'Cancelled']],
            'Boarding' => [FlightStatus::Boarding, ['Delayed']],      // never Closed: that is closeBoarding()
            'Delayed' => [FlightStatus::Delayed, ['CheckinOpen', 'Boarding', 'Cancelled']],
            'Closed' => [FlightStatus::Closed, ['Departed']],
            'Departed' => [FlightStatus::Departed, []],
            'Cancelled' => [FlightStatus::Cancelled, []],
        ];
    }

    /**
     * @param  list<string>  $offered
     */
    #[DataProvider('buttonsPerStatus')]
    public function test_status_buttons_only_show_allowed_moves(FlightStatus $current, array $offered): void
    {
        $this->flightWithStatus($current);

        $flight = Flight::query()->latest('id')->firstOrFail();
        $response = $this->get(route('manager.flights.show', ['flight' => $flight, 'tab' => 'status']))->assertOk();

        foreach (FlightStatus::cases() as $status) {
            $button = 'name="status" value="'.$status->value.'"';

            in_array($status->value, $offered, true)
                ? $response->assertSee($button, false)
                : $response->assertDontSee($button, false);
        }
    }

    // ── Gate (SR-42) ─────────────────────────────────────────────────────────

    public function test_SR42_changed_gate_shows_on_checkin_and_gate_pages(): void
    {
        // The gate page (G1) and boarding pass (C5) are not built yet: only the check-in page
        // and the shared today's-flights table (reservation, check-in and manager pages) show a gate today.
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['gate' => 'A1']);
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();

        $this->patch(route('manager.flights.gate', $flight), ['gate' => 'C9'])
            ->assertSessionHasNoErrors();

        $this->assertSame('C9', $flight->fresh()->gate);

        $this->actingAsRole(Role::Checkin);
        $this->get(route('checkin.passengers.show', $passenger))->assertOk()->assertSee('Gate C9');
        $this->get(route('checkin.home'))->assertOk()->assertSee('C9')->assertDontSee('>A1<', false);

        $this->actingAsRole(Role::Reservation);
        $this->get(route('reservation.create'))->assertOk()->assertSee('C9');
    }

    public function test_an_empty_gate_clears_the_gate(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Scheduled, ['gate' => 'A1']);

        $this->patch(route('manager.flights.gate', $flight), ['gate' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($flight->fresh()->gate);
    }

    public function test_invalid_gate_is_rejected(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Scheduled, ['gate' => 'A1']);

        foreach (['AB', '12', 'A123'] as $bad) {
            $this->patch(route('manager.flights.gate', $flight), ['gate' => $bad])->assertSessionHasErrors('gate');
        }

        $this->assertSame('A1', $flight->fresh()->gate);
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_non_manager_cannot_open_or_post(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Scheduled, ['gate' => 'A1']);

        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);

            $this->get(route('manager.flights'))->assertForbidden();
            $this->post(route('manager.aircraft.store'), $this->validAircraft())->assertForbidden();
            $this->post(route('manager.flights.store'), $this->validFlight())->assertForbidden();
            $this->patch(route('manager.flights.status', $flight), ['status' => 'CheckinOpen'])->assertForbidden();
            $this->patch(route('manager.flights.gate', $flight), ['gate' => 'Z9'])->assertForbidden();
        }

        $this->assertSame(0, Aircraft::where('registration', 'OD-TST')->count());
        $this->assertSame(1, Flight::count());
        $flight->refresh();
        $this->assertSame(FlightStatus::Scheduled, $flight->status);
        $this->assertSame('A1', $flight->gate);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Scheduled);
        auth()->logout();

        $this->get(route('manager.flights'))->assertRedirect(route('login'));
        $this->post(route('manager.aircraft.store'), $this->validAircraft())->assertRedirect(route('login'));
        $this->post(route('manager.flights.store'), $this->validFlight())->assertRedirect(route('login'));
        $this->patch(route('manager.flights.status', $flight), ['status' => 'CheckinOpen'])->assertRedirect(route('login'));
        $this->patch(route('manager.flights.gate', $flight), ['gate' => 'Z9'])->assertRedirect(route('login'));
    }
}
