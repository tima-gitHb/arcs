<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Models\Bag;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UI3CheckInShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->seedBookingPrices();
    }

    public function test_UI3_checkin_home_shows_search_and_menu_actions(): void
    {
        $this->actingAsRole('checkin');

        $this->get(route('checkin.home'))
            ->assertOk()
            ->assertSee('id="q"', false)
            ->assertSee('autofocus', false)
            ->assertSee('Find booking')
            ->assertSee('Checking in now')
            ->assertSee("Today's flights", false)
            ->assertSee('data-role="checkin"', false)
            ->assertSee('Check in passenger')
            ->assertSee('Profile')
            ->assertSee('Log out')
            ->assertSee('data-user-card', false);
    }

    public function test_UI3_passenger_checkin_page_shows_the_six_step_labels(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        $this->actingAsRole('checkin');
        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Documents')
            ->assertSee('Seat')
            ->assertSee('Bags & equipment')
            ->assertSee('Services')
            ->assertSee('Payment')
            ->assertSee('Done')
            ->assertSee('data-checkin-flow', false)
            ->assertSee('id="seat-map-panel"', false);
    }

    public function test_UI3_payment_step_shows_nothing_to_pay_when_total_is_zero(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        $this->actingAsRole('checkin');
        $this->get(route('checkin.passengers.show', $passenger).'#payment')
            ->assertOk()
            ->assertSee('Nothing to pay')
            ->assertSee('Confirm check-in');
    }

    public function test_UI3_done_page_shows_boarding_pass_and_bag_tag_numbers(): void
    {
        $agent = $this->actingAsRole('checkin');
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();
        $passenger->reservation->update(['contact_email' => null]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);
        $bag = Bag::factory()->for($passenger)->create(['weight_kg' => 21]);

        $this->post(route('checkin.passengers.confirm', $passenger))->assertRedirect();
        $passenger->refresh();
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->status);

        $pass = $passenger->boardingPasses()->whereNull('voided_at')->first();
        $this->assertNotNull($pass);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee($passenger->first_name.' '.$passenger->last_name.' is checked in')
            ->assertSee('Boarding pass')
            ->assertSee($pass->barcode)
            ->assertSee($bag->tag_no)
            ->assertSee('Print boarding passes')
            ->assertSee('Print bag tags')
            ->assertSee('Next passenger →');
    }

    public function test_UI3_services_step_uses_toggle_cards_with_code_values(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        $this->actingAsRole('checkin');
        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('name="code"', false)
            ->assertSee('value="WCHR"', false)
            ->assertSee('type="radio"', false);
    }

    public function test_UI3_bags_step_has_weight_pills(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        $this->actingAsRole('checkin');
        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('+ 18 kg')
            ->assertSee('+ 23 kg')
            ->assertSee('+ 28 kg')
            ->assertSee('name="weight_kg"', false);
    }

    public function test_UI3_flight_list_status_pills_filter_waiting_and_done(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $waiting = Reservation::factory()->for($flight)->create();
        Passenger::factory()->for($waiting)->create();
        $done = Reservation::factory()->for($flight)->create();
        $checked = Passenger::factory()->for($done)->create();
        TravelDocument::factory()->for($checked)->create();
        $this->seatPassenger($checked);
        $this->actingAsRole('checkin');
        $this->post(route('checkin.passengers.confirm', $checked))->assertRedirect();

        $all = $this->get(route('checkin.flights.show', $flight))->assertOk();
        $all->assertSee('data-status-pills', false);
        $all->assertSee($waiting->pnr);
        $all->assertSee($done->pnr);

        $waitingPage = $this->get(route('checkin.flights.show', ['flight' => $flight, 'status' => 'waiting']))->assertOk();
        $waitingPage->assertSee($waiting->pnr);
        $waitingPage->assertDontSee($done->pnr);

        $donePage = $this->get(route('checkin.flights.show', ['flight' => $flight, 'status' => 'done']))->assertOk();
        $donePage->assertSee($done->pnr);
        $donePage->assertDontSee($waiting->pnr);
    }

    public function test_UI3_station_card_shows_code_and_city(): void
    {
        $this->actingAsRole('checkin');

        $this->get(route('checkin.home'))
            ->assertOk()
            ->assertSee('BEY · Beirut', false);
    }
}
