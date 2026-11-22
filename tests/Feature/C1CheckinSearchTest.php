<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class C1CheckinSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // flightWithStatus(): check-in window 09:00–11:15, departure 12:00.
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
    }

    private function passengerOn(Flight $flight, string $lastName = 'Haddad', ?string $pnr = null): Passenger
    {
        $reservation = Reservation::factory()->for($flight)->create($pnr ? ['pnr' => $pnr] : []);
        $passenger = Passenger::factory()->for($reservation)->create(['first_name' => 'Rami', 'last_name' => $lastName]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger); // check-in needs a seat (C5)

        return $passenger;
    }

    /**
     * Press "Confirm check-in" on the passenger's check-in screen.
     */
    private function confirmCheckIn(Passenger $passenger)
    {
        return $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.passengers.confirm', $passenger));
    }

    // --- Check-in screen -----------------------------------------------------------------

    public function test_opening_the_check_in_screen_does_not_change_the_status(): void
    {
        $passenger = $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen));

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Rami Haddad')
            ->assertSee('Booked')
            ->assertSee('Confirm check-in')
            ->assertSee(route('checkin.passengers.confirm', $passenger), false);

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
        $this->assertNull($passenger->fresh()->checked_in_at);
        $this->assertSame(0, AuditEntry::whereNotNull('passenger_id')->count());
    }

    // --- Confirming a check-in -----------------------------------------------------------

    public function test_SR6_confirm_check_in_of_cancelled_passenger_is_refused(): void
    {
        $passenger = $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen));
        app(StatusService::class)->cancel($passenger, User::factory()->create(['role' => Role::Reservation]));

        $this->confirmCheckIn($passenger)
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error', 'Reservation cancelled');

        $this->assertSame(PassengerStatus::Cancelled, $passenger->fresh()->status);
    }

    public function test_SR30_confirm_check_in_10_minutes_after_window_closes_is_refused(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = $this->passengerOn($flight);

        $this->travelTo($flight->checkin_closes_at->copy()->addMinutes(10));

        $this->confirmCheckIn($passenger)
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error', 'Check-in closed');
        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    public function test_SR25_confirm_check_in_refused_when_flight_not_checkin_open(): void
    {
        $passenger = $this->passengerOn($this->flightWithStatus(FlightStatus::Scheduled));

        $this->confirmCheckIn($passenger)
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('error', 'Check-in closed');
        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    public function test_refused_confirm_stays_on_the_screen_with_a_red_flash(): void
    {
        $passenger = $this->passengerOn($this->flightWithStatus(FlightStatus::Scheduled));

        $this->followingRedirects()
            ->post(route('checkin.passengers.confirm', $passenger))
            ->assertOk()
            ->assertSee('Rami Haddad')
            ->assertSee('Confirm check-in')
            ->assertSee('role="alert"', false)
            ->assertSee('Check-in closed');
    }

    public function test_SR6_successful_confirm_checks_the_passenger_in(): void
    {
        $passenger = $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen));

        $this->confirmCheckIn($passenger)
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('success', 'Rami Haddad checked in.');

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
        $this->assertTrue($passenger->fresh()->checkedInBy->user->is($this->agent));
        $this->assertSame(1, AuditEntry::where('field', 'status')->count());
        // Confirming also issues the boarding pass (C5).
        $this->assertSame(1, AuditEntry::where('field', 'boarding_pass_issued')->count());

        // Once checked in, the screen shows who did it and no longer offers the button.
        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Checked in')
            ->assertSee($this->agent->name)
            ->assertDontSee('Confirm check-in');
    }

    public function test_only_checkin_staff_can_open_or_confirm(): void
    {
        $passenger = $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen));

        $this->actingAsRole('gate');
        $this->get(route('checkin.passengers.show', $passenger))->assertForbidden();
        $this->post(route('checkin.passengers.confirm', $passenger))->assertForbidden();

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    // --- Home and search -----------------------------------------------------------------

    public function test_SR41_check_in_home_shows_todays_flights(): void
    {
        Flight::factory()->create(['flight_no' => $this->flightNo('900'), 'departure_at' => '2026-11-09 18:00']);
        Flight::factory()->create(['flight_no' => $this->flightNo('815'), 'destination' => 'FCO', 'departure_at' => '2026-11-10 15:00']);
        Flight::factory()->create(['flight_no' => $this->flightNo('801'), 'destination' => 'CDG', 'departure_at' => '2026-11-10 08:00']);

        $this->get(route('checkin.home'))
            ->assertOk()
            ->assertSee("Today's flights", false)
            ->assertSee('data-role="checkin"', false)
            ->assertSeeInOrder([$this->flightNo('801'), 'BEY → CDG', $this->flightNo('815'), 'BEY → FCO'])
            ->assertDontSee($this->flightNo('900'));
    }

    public function test_search_by_pnr_finds_the_reservation(): void
    {
        $passenger = $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen), 'Khoury', 'K7M2NP');
        $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen), 'Saliba', 'ZZ9ZZ9');

        $this->get(route('checkin.home', ['q' => 'k7m2np']))
            ->assertOk()
            ->assertSee('K7M2NP')
            ->assertSee('Rami Khoury')
            ->assertDontSee('Saliba')
            ->assertSee('href="'.route('checkin.passengers.show', $passenger).'"', false);
    }

    public function test_search_by_last_name_finds_the_reservation(): void
    {
        $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen), 'Bou Khalil', 'BK2345');
        $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen), 'Saliba', 'SA2345');

        $this->get(route('checkin.home', ['q' => 'bou khalil']))
            ->assertOk()
            ->assertSee('BK2345')
            ->assertSee('Rami Bou Khalil')
            ->assertDontSee('SA2345');
    }

    public function test_unknown_pnr_shows_not_found(): void
    {
        $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen), 'Haddad', 'K7M2NP');

        $this->get(route('checkin.home', ['q' => 'XXXXXX']))
            ->assertOk()
            ->assertSee('not found')
            ->assertDontSee('K7M2NP');
    }

    public function test_SR30_results_show_status_flight_status_and_window(): void
    {
        $open = $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen), 'Haddad', 'OPEN22');
        $this->passengerOn($this->flightWithStatus(FlightStatus::Delayed), 'Haddad', 'LATE22');
        $closedWindow = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'checkin_opens_at' => now()->subHours(3),
            'checkin_closes_at' => now()->subHour(),
        ]);
        $this->passengerOn($closedWindow, 'Haddad', 'SHUT22');

        $checkedIn = $this->passengerOn($open->reservation->flight, 'Haddad', 'DONE22');
        app(StatusService::class)->checkIn($checkedIn, $this->agent);

        $response = $this->get(route('checkin.home', ['q' => 'Haddad']))->assertOk();

        $response->assertSee('Booked')
            ->assertSee('Checked in')
            ->assertSee('Check-in open')   // flight status labels
            ->assertSee('Delayed')
            ->assertSee('Window open')
            ->assertSee('Window closed');

        // "Check in" opens the screen; nothing on the search page changes a status.
        $response->assertSee('href="'.route('checkin.passengers.show', $open).'"', false)
            ->assertSee('href="'.route('checkin.passengers.show', $checkedIn).'"', false)
            ->assertDontSee('/confirm', false);
        $this->assertSame(PassengerStatus::Booked, $open->fresh()->status);
    }

    public function test_search_excludes_flights_that_departed_before_today(): void
    {
        $yesterday = Flight::factory()->create(['departure_at' => '2026-11-09 18:00']);
        $this->passengerOn($yesterday, 'Haddad', 'PAST22');
        $earlierToday = Flight::factory()->create(['departure_at' => '2026-11-10 06:00']);
        $this->passengerOn($earlierToday, 'Haddad', 'TODAY2');

        $this->get(route('checkin.home', ['q' => 'Haddad']))
            ->assertOk()
            ->assertSee('TODAY2')
            ->assertDontSee('PAST22');

        $this->get(route('checkin.home', ['q' => 'PAST22']))
            ->assertOk()
            ->assertSee('not found');
    }
}
