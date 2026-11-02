<?php

namespace Tests\Feature\Manager;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Enums\SpecialServiceCode;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\SpecialService;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\AircraftService;
use App\Services\ReservationService;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * M3 part 1 — Flight lists + print view (SR-2, SR-45). Read only.
 *
 * The page is manager.home. GET parameters: flight (id) and tab (passengers | baggage | services).
 * Passengers, check-ins, boardings and seats are created through the services. Bags and special
 * services are created directly: no service writes them yet (the seeder does the same).
 *
 * What the page must show:
 *   picker      "ME201 — 10 Nov 2026" (flight number and date), tab links, a Print button (window.print())
 *   passengers  columns in this order: name ("First Last"), PNR, seat, status, checked in by + time,
 *               boarded by + time (time as "10 Nov 2026 10:00"); ordered by last name, then first name
 *   baggage     tag, passenger, type, weight (one decimal), handling, offloaded (Yes / No)
 *               and under it "Total: 4 bags, 68.0 kg" ("1 bag" when there is one)
 *   services    passenger, IATA code, details
 */
class FlightListTest extends TestCase
{
    use RefreshDatabase;

    private User $reservationAgent;

    private User $checkinAgent;

    private User $gateAgent;

    private User $manager;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        // flightWithStatus() opens the check-in window around now(): 09:00 to 11:15, departure 12:00.
        $this->travelTo('2026-11-10 10:00:00');

        $this->manager = $this->actingAsRole(Role::Manager);
        $this->reservationAgent = User::factory()->create(['role' => Role::Reservation, 'name' => 'Reserv Rita']);
        $this->checkinAgent = User::factory()->create(['role' => Role::Checkin, 'name' => 'Checkin Carl']);
        $this->gateAgent = User::factory()->create(['role' => Role::Gate, 'name' => 'Gate Gina']);

        $this->flight = $this->newFlight('ME201');
    }

    /**
     * A flight on a small aircraft that really has seats (5 rows x A–D), check-in open.
     */
    private function newFlight(string $number): Flight
    {
        $aircraft = app(AircraftService::class)->create([
            'registration' => 'OD-'.$number,
            'model' => 'A320',
            'rows' => 5,
            'seats_per_row' => 4,
            'business_rows' => 1,
        ]);

        return $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => $number,
            'aircraft_id' => $aircraft->id,
        ]);
    }

    /**
     * A booked passenger (with an optional seat) made by ReservationService.
     * With $checkedIn, the passenger is also checked in through StatusService.
     */
    private function addPassenger(Flight $flight, string $first, string $last, ?string $seatNo = null, bool $checkedIn = false): Passenger
    {
        $reservation = app(ReservationService::class)->create($flight, $this->reservationAgent, [[
            'first_name' => $first,
            'last_name' => $last,
            'type' => 'Adult',
            'date_of_birth' => '1990-01-01',
            'seat_id' => $seatNo ? $flight->aircraft->seats()->where('seat_no', $seatNo)->value('id') : null,
        ]]);

        $passenger = $reservation->passengers->first();

        if ($checkedIn) {
            TravelDocument::factory()->for($passenger)->create();
            app(StatusService::class)->checkIn($passenger, $this->checkinAgent);
        }

        return $passenger;
    }

    private function bag(Passenger $passenger, string $tag, BagType $type, BagHandling $handling, float $weight, bool $offloaded = false): Bag
    {
        return Bag::create([
            'passenger_id' => $passenger->id,
            'tag_no' => $tag,
            'type' => $type,
            'handling' => $handling,
            'weight_kg' => $weight,
            'offloaded_at' => $offloaded ? now() : null,
        ]);
    }

    private function openList(Flight $flight, ?string $tab = null): TestResponse
    {
        return $this->get(route('manager.home', array_filter(['flight' => $flight->id, 'tab' => $tab])))->assertOk();
    }

    public function test_SR2_passenger_list_matches_recorded_data(): void
    {
        $adam = $this->addPassenger($this->flight, 'Adam', 'Zeidan', '2A');
        $lina = $this->addPassenger($this->flight, 'Lina', 'Karam', '2B', checkedIn: true);
        $maya = $this->addPassenger($this->flight, 'Maya', 'Abboud', '2C');
        app(StatusService::class)->cancel($maya, $this->reservationAgent); // the seat is released

        $response = $this->openList($this->flight, 'passengers')
            ->assertSee('Checked in by', false) // this tab's own heading
            // Cancelled passengers are part of the full record of the flight.
            // Ordered by last name: Abboud, Karam, Zeidan.
            ->assertSeeInOrder(['Maya Abboud', 'Lina Karam', 'Adam Zeidan'])
            ->assertSeeInOrder(['Adam Zeidan', $adam->reservation->pnr, '2A', PassengerStatus::Booked->label()])
            ->assertSeeInOrder(['Lina Karam', $lina->reservation->pnr, '2B', PassengerStatus::CheckedIn->label()])
            ->assertSeeInOrder(['Maya Abboud', $maya->reservation->pnr, PassengerStatus::Cancelled->label()]);

        $response->assertDontSee('2C');   // the cancelled passenger's seat was released
    }

    public function test_SR45_passenger_list_shows_who_checked_in_and_boarded(): void
    {
        $lina = $this->addPassenger($this->flight, 'Lina', 'Karam', '2B', checkedIn: true); // 10:00 by Carl

        app(StatusService::class)->setFlightStatus($this->flight, FlightStatus::Boarding, $this->manager);
        $this->travelTo('2026-11-10 10:30:00');
        app(StatusService::class)->board($lina, $this->gateAgent);

        $this->openList($this->flight, 'passengers')
            ->assertSeeInOrder([
                'Lina Karam',
                'Checkin Carl', '10 Nov 2026 10:00',
                'Gate Gina', '10 Nov 2026 10:30',
            ]);
    }

    public function test_SR2_baggage_list_shows_every_bag_with_its_weight(): void
    {
        $adam = $this->addPassenger($this->flight, 'Adam', 'Zeidan', '2A', checkedIn: true);
        $lina = $this->addPassenger($this->flight, 'Lina', 'Karam', '2B', checkedIn: true);

        $this->bag($lina, 'ME1001', BagType::Standard, BagHandling::Hold, 23.5);
        $this->bag($lina, 'ME1002', BagType::Wheelchair, BagHandling::GateDelivery, 14.0);
        $this->bag($adam, 'ME1003', BagType::Stroller, BagHandling::GateDelivery, 9.0);
        $this->bag($adam, 'ME1004', BagType::SportsEquipment, BagHandling::Hold, 21.5, offloaded: true);

        $response = $this->openList($this->flight, 'baggage')
            ->assertSee('Tag', false) // this tab's own heading
            // tag, passenger, type, weight, handling, offloaded — wheelchair and stroller weights too.
            ->assertSeeInOrder(['ME1001', 'Lina Karam', BagType::Standard->label(), '23.5', BagHandling::Hold->label(), 'No'])
            ->assertSeeInOrder(['ME1002', 'Lina Karam', BagType::Wheelchair->label(), '14.0', BagHandling::GateDelivery->label(), 'No'])
            ->assertSeeInOrder(['ME1003', 'Adam Zeidan', BagType::Stroller->label(), '9.0', BagHandling::GateDelivery->label(), 'No'])
            ->assertSeeInOrder(['ME1004', 'Adam Zeidan', BagType::SportsEquipment->label(), '21.5', BagHandling::Hold->label(), 'Yes']);

        // Only the one offloaded bag says Yes.
        $this->assertSame(1, substr_count($response->getContent(), 'Yes'));
    }

    public function test_baggage_totals_line_shows_count_and_total_weight(): void
    {
        $this->openList($this->flight, 'baggage')->assertSee('Total: 0 bags, 0.0 kg');

        $lina = $this->addPassenger($this->flight, 'Lina', 'Karam', '2B', checkedIn: true);
        $this->bag($lina, 'ME1001', BagType::Standard, BagHandling::Hold, 15.5);

        $this->openList($this->flight, 'baggage')->assertSee('Total: 1 bag, 15.5 kg');

        $this->bag($lina, 'ME1002', BagType::Wheelchair, BagHandling::GateDelivery, 14.0);
        $this->bag($lina, 'ME1003', BagType::Stroller, BagHandling::GateDelivery, 9.0);
        $this->bag($lina, 'ME1004', BagType::SportsEquipment, BagHandling::Hold, 21.5, offloaded: true);

        // Every bag counts, free ones and offloaded ones too: 15.5 + 14.0 + 9.0 + 21.5.
        $this->openList($this->flight, 'baggage')->assertSee('Total: 4 bags, 60.0 kg');
    }

    public function test_SR2_special_service_list_matches_recorded_data(): void
    {
        $adam = $this->addPassenger($this->flight, 'Adam', 'Zeidan', '2A');
        $lina = $this->addPassenger($this->flight, 'Lina', 'Karam', '2B', checkedIn: true);

        SpecialService::create(['passenger_id' => $adam->id, 'type' => SpecialServiceCode::PETC, 'details' => 'Cat, 6 kg with carrier']);
        SpecialService::create(['passenger_id' => $lina->id, 'type' => SpecialServiceCode::WCHR]);

        $this->openList($this->flight, 'services')
            ->assertSee('Code', false) // this tab's own heading
            ->assertSeeInOrder(['Adam Zeidan', 'PETC', 'Cat, 6 kg with carrier'])
            ->assertSeeInOrder(['Lina Karam', 'WCHR']);
    }

    public function test_lists_only_show_the_chosen_flight(): void
    {
        $other = $this->newFlight('ME305');

        $mine = $this->addPassenger($this->flight, 'Lina', 'Karam', '2B', checkedIn: true);
        $this->bag($mine, 'ME1001', BagType::Standard, BagHandling::Hold, 20.0);
        SpecialService::create(['passenger_id' => $mine->id, 'type' => SpecialServiceCode::PETC, 'details' => 'Parrot']);

        $theirs = $this->addPassenger($other, 'Sami', 'Otherflight', '2B', checkedIn: true);
        $this->bag($theirs, 'ME2002', BagType::Standard, BagHandling::Hold, 18.0);
        SpecialService::create(['passenger_id' => $theirs->id, 'type' => SpecialServiceCode::MEDA, 'details' => 'Insulin']);

        $this->openList($this->flight, 'passengers')->assertSee('Lina Karam')->assertDontSee('Otherflight');
        $this->openList($this->flight, 'baggage')->assertSee('ME1001')->assertDontSee('ME2002')->assertSee('Total: 1 bag, 20.0 kg');
        $this->openList($this->flight, 'services')->assertSee('Parrot')->assertDontSee('Insulin');

        // And the other way round.
        $this->openList($other, 'passengers')->assertSee('Sami Otherflight')->assertDontSee('Karam');
        $this->openList($other, 'baggage')->assertSee('ME2002')->assertDontSee('ME1001');
        $this->openList($other, 'services')->assertSee('Insulin')->assertDontSee('Parrot');
    }

    public function test_no_flight_chosen_shows_the_picker_only(): void
    {
        $this->newFlight('ME305');
        $this->addPassenger($this->flight, 'Lina', 'Karam', '2B', checkedIn: true);

        // The picker lists every flight by number and date.
        $this->get(route('manager.home'))
            ->assertOk()
            ->assertSee('Flight lists')
            ->assertSee('ME201 — 10 Nov 2026')
            ->assertSee('ME305 — 10 Nov 2026')
            ->assertDontSee('Karam')
            ->assertDontSee('Special services')   // no tabs
            ->assertDontSee('window.print()', false);   // no Print button

        // An unknown flight id is treated the same way.
        $this->get(route('manager.home', ['flight' => 99999]))
            ->assertOk()
            ->assertSee('ME201 — 10 Nov 2026')
            ->assertDontSee('Special services');
    }

    public function test_default_and_unknown_tab_show_the_passenger_list_and_only_one_list_at_a_time(): void
    {
        $lina = $this->addPassenger($this->flight, 'Lina', 'Karam', '2B', checkedIn: true);
        $this->bag($lina, 'ME1001', BagType::Standard, BagHandling::Hold, 20.0);

        foreach ([null, 'bogus', 'passengers'] as $tab) {
            $this->openList($this->flight, $tab)
                ->assertSee('Lina Karam')
                ->assertSee('Checked in by', false)
                ->assertDontSee('ME1001');
        }

        $this->openList($this->flight, 'baggage')->assertSee('ME1001')->assertDontSee('Checked in by', false);
    }

    public function test_print_button_tabs_and_print_styles_are_on_the_page(): void
    {
        $this->openList($this->flight, 'baggage')
            ->assertSee('window.print()', false)
            ->assertSee('@media print', false)
            // one link per tab, each keeping the chosen flight
            ->assertSee('flight='.$this->flight->id.'&amp;tab=passengers', false)
            ->assertSee('flight='.$this->flight->id.'&amp;tab=baggage', false)
            ->assertSee('flight='.$this->flight->id.'&amp;tab=services', false);
    }

    public function test_lists_use_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            $total = 0;

            foreach (['passengers', 'baggage', 'services'] as $tab) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->openList($this->flight, $tab);
                $total += count(DB::getQueryLog());
            }

            DB::disableQueryLog();

            return $total;
        };

        $seats = ['2A', '2B', '2C', '2D', '3A', '3B', '3C', '3D', '4A', '4B', '4C', '4D'];
        $add = function (int $from, int $to) use ($seats) {
            for ($i = $from; $i < $to; $i++) {
                $passenger = $this->addPassenger($this->flight, 'First'.$i, 'Last'.$i, $seats[$i], checkedIn: true);
                $this->bag($passenger, 'ME9'.$i, BagType::Standard, BagHandling::Hold, 20.0);
                SpecialService::create(['passenger_id' => $passenger->id, 'type' => SpecialServiceCode::VGML]);
            }
        };

        $add(0, 3);
        $few = $count();

        $add(3, 12);
        $many = $count();

        $this->assertSame($few, $many, 'The number of queries must not grow with the number of passengers.');
    }

    public function test_non_manager_cannot_open_flight_lists(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->get(route('manager.home'))->assertForbidden();
            $this->get(route('manager.home', ['flight' => $this->flight->id, 'tab' => 'baggage']))->assertForbidden();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get(route('manager.home'))->assertRedirect(route('login'));
        $this->get(route('manager.home', ['flight' => $this->flight->id]))->assertRedirect(route('login'));
    }
}
