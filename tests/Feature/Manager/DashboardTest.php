<?php

namespace Tests\Feature\Manager;

use App\Enums\FlightStatus;
use App\Enums\Role;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed time of day, so "today" and "the next 2 hours" never cross midnight.
        $this->travelTo(now()->setTime(10, 0));
    }

    /**
     * Add $total passengers to the flight, each with a valid passport.
     * The first $checkedIn of them are checked in through StatusService (never by setting the status).
     *
     * @return list<Passenger>
     */
    private function addPassengers(Flight $flight, int $total, int $checkedIn): array
    {
        $service = app(StatusService::class);
        $agent = User::factory()->create(['role' => Role::Checkin]);
        $passengers = [];

        for ($i = 0; $i < $total; $i++) {
            $passenger = Passenger::factory()
                ->for(Reservation::factory()->for($flight))
                ->create();

            TravelDocument::factory()->for($passenger)->create();

            if ($i < $checkedIn) {
                $this->seatPassenger($passenger); // check-in needs a seat (C5)
                $service->checkIn($passenger, $agent);
            }

            $passengers[] = $passenger;
        }

        return $passengers;
    }

    public function test_SR47_dashboard_shows_checked_in_percentage(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => $this->flightNo('901')]);
        $this->addPassengers($flight, total: 10, checkedIn: 8);

        $this->actingAsRole('manager');

        $this->get('/manager/dashboard')
            ->assertOk()
            ->assertSee($this->flightNo('901'))
            ->assertSee('80 %');
    }

    public function test_dashboard_shows_boarded_no_shows_and_bags(): void
    {
        $service = app(StatusService::class);
        $gate = User::factory()->create(['role' => Role::Gate]);

        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => $this->flightNo('901')]);
        [$p1, $p2, $p3, $p4, $p5] = $this->addPassengers($flight, total: 5, checkedIn: 5);

        // 4 bags: two for one passenger, and one for a passenger who will become NoShow.
        Bag::factory()->for($p1)->count(2)->create();
        Bag::factory()->for($p2)->create();
        Bag::factory()->for($p5)->create();

        // 3 board; closing boarding turns the 2 who are still CheckedIn into NoShow (SR-12).
        $service->setFlightStatus($flight, FlightStatus::Boarding, $gate);
        $service->board($p1, $gate);
        $service->board($p2, $gate);
        $service->board($p3, $gate);
        $service->closeBoarding($flight, $gate);

        $this->actingAsRole('manager');

        // The three counts differ on purpose (3, 2, 4), so a mix-up between them is caught.
        // Boarded and NoShow passengers were checked in, so all 5 count: 100 %.
        $this->get('/manager/dashboard')
            ->assertOk()
            ->assertSee('data-figure="boarded">3<', false)
            ->assertSee('data-figure="no-shows">2<', false)
            ->assertSee('data-figure="bags">4<', false)
            ->assertSee('100 %');
    }

    public function test_SR41_only_todays_flights_appear(): void
    {
        // Today: use the usual helper. Yesterday / tomorrow must not have a check-in window
        // open now (BUG-013 also lists window-open flights), so build them with closed windows.
        $this->flightWithStatus(FlightStatus::Scheduled, ['flight_no' => $this->flightNo('901')]);
        Flight::factory()->create([
            'flight_no' => $this->flightNo('902'),
            'origin' => 'BEY',
            'destination' => 'DXB',
            'departure_at' => now()->subDay(),
            'checkin_opens_at' => now()->subDay()->subHours(3),
            'checkin_closes_at' => now()->subDay()->subMinutes(45),
        ]);
        Flight::factory()->create([
            'flight_no' => $this->flightNo('903'),
            'origin' => 'BEY',
            'destination' => 'FCO',
            'departure_at' => now()->addDay(),
            'checkin_opens_at' => now()->addDay()->subHours(3),
            'checkin_closes_at' => now()->addDay()->subMinutes(45),
        ]);

        $this->actingAsRole('manager');

        $this->get('/manager/dashboard')
            ->assertOk()
            ->assertSee($this->flightNo('901'))
            ->assertDontSee($this->flightNo('902'))
            ->assertDontSee($this->flightNo('903'));
    }

    public function test_non_manager_cannot_open_dashboard(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->get('/manager/dashboard')->assertForbidden();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/manager/dashboard')->assertRedirect(route('login'));
    }
}
