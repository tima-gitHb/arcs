<?php

namespace Tests\Feature\Gate;

use App\Enums\BagType;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class G3CloseBoardingTest extends TestCase
{
    use BuildsGateFlights;
    use RefreshDatabase;

    private Flight $flight;

    /** @var array<string, list<Passenger>> */
    private array $people;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->makeAgents();
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME305']);

        // 2 boarded, 3 checked in but missing, 1 never checked in.
        $this->people = [
            'boarded' => [$this->named('Boarded', 'One'), $this->named('Boarded', 'Two')],
            'missing' => [$this->named('Missing', 'Ali'), $this->named('Missing', 'Bea'), $this->named('Missing', 'Cy')],
            'booked' => [$this->named('Booked', 'Dan')],
        ];
        $this->checkIn(...$this->people['boarded'], ...$this->people['missing']);
        $this->startBoarding($this->flight);
        $this->board(...$this->people['boarded']);
    }

    private function named(string $last, string $first): Passenger
    {
        return $this->passengerOn($this->flight, null, ['last_name' => $last, 'first_name' => $first]);
    }

    private function closeBoarding()
    {
        return $this->from(route('gate.flights.show', $this->flight))
            ->post(route('gate.flights.close', $this->flight));
    }

    /**
     * The offload section of the manifest page.
     */
    private function offloadSection(): string
    {
        $html = $this->get(route('gate.flights.show', $this->flight))->assertOk()->getContent();
        preg_match('/<section[^>]*id="offload"[\s\S]*?<\/section>/', $html, $match);

        return $match[0] ?? '';
    }

    public function test_SR12_closing_sets_exactly_the_missing_checked_in_passengers_to_no_show(): void
    {
        $this->closeBoarding()
            ->assertRedirect(route('gate.flights.show', $this->flight))
            ->assertSessionHas('success', 'Boarding closed: 3 no-shows, 0 bags to offload.');

        foreach ($this->people['missing'] as $passenger) {
            $this->assertSame(PassengerStatus::NoShow, $passenger->fresh()->status);
            $this->assertTrue($passenger->fresh()->markedNoShowBy->user->is($this->gateAgent));
        }
        foreach ($this->people['boarded'] as $passenger) {
            $this->assertSame(PassengerStatus::Boarded, $passenger->fresh()->status);
        }
        $this->assertSame(PassengerStatus::Booked, $this->people['booked'][0]->fresh()->status);

        $this->assertSame(FlightStatus::Closed, $this->flight->fresh()->status);
        $this->getJson(route('gate.flights.counts', $this->flight))
            ->assertExactJson(['checked_in' => 5, 'boarded' => 2, 'missing' => 3, 'status' => 'Closed']);
    }

    public function test_SR9_the_no_shows_bags_appear_on_the_offload_list(): void
    {
        [$ali, $bea] = $this->people['missing'];
        Bag::factory()->for($ali)->create(['tag_no' => 'ME100001', 'weight_kg' => 18.0]);
        Bag::factory()->for($ali)->create(['tag_no' => 'ME100002', 'weight_kg' => 9.0, 'type' => BagType::Stroller]);
        Bag::factory()->for($bea)->create(['tag_no' => 'ME100003', 'weight_kg' => 23.0]);
        Bag::factory()->for($this->people['boarded'][0])->create(['tag_no' => 'ME100009']);

        $this->closeBoarding()->assertSessionHas('success', 'Boarding closed: 3 no-shows, 3 bags to offload.');

        $section = $this->offloadSection();
        $this->assertStringContainsString('Bags to offload', $section);
        $aliSeat = $ali->seatAssignment->seat->seat_no;
        foreach (['ME100001', '18.0 kg', 'ME100002', 'Stroller', 'ME100003', '23.0 kg', 'Ali Missing', $aliSeat] as $text) {
            $this->assertStringContainsString($text, $section);
        }
        $this->assertStringNotContainsString('ME100009', $section); // boarded passenger's bag stays on
    }

    public function test_closing_needs_an_on_page_confirmation_step(): void
    {
        $closeAction = 'action="'.route('gate.flights.close', $this->flight).'"';

        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSee(route('gate.flights.show', [$this->flight, 'close' => 'confirm']), false)
            ->assertDontSee($closeAction, false)
            ->assertDontSee('confirm(', false);

        $this->get(route('gate.flights.show', [$this->flight, 'close' => 'confirm']))
            ->assertOk()
            ->assertSee('3 checked-in passengers have not boarded')
            ->assertSee($closeAction, false)
            ->assertSee('Yes, close boarding');

        // Just showing the confirmation changes nothing.
        $this->assertSame(FlightStatus::Boarding, $this->flight->fresh()->status);
    }

    public function test_after_closing_boarding_is_refused_and_the_pnr_box_is_hidden(): void
    {
        $this->closeBoarding();
        $late = $this->people['missing'][0];

        $this->from(route('gate.flights.show', $this->flight))
            ->post(route('gate.flights.board', $this->flight), ['pnr' => $late->reservation->pnr])
            ->assertSessionHas('error', 'Boarding is closed for this flight.');
        $this->assertSame(PassengerStatus::NoShow, $late->fresh()->status);

        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSee('Boarding is closed for this flight.')
            ->assertDontSee('name="pnr"', false)
            ->assertDontSee(route('gate.flights.board', $this->flight), false)
            ->assertDontSee(route('gate.flights.show', [$this->flight, 'close' => 'confirm']), false);
    }

    public function test_closing_twice_is_refused_with_a_clear_message(): void
    {
        $this->closeBoarding()->assertSessionHas('success');

        $this->closeBoarding()
            ->assertRedirect(route('gate.flights.show', $this->flight))
            ->assertSessionHas('error', 'Boarding is already closed for this flight.');
    }

    public function test_closing_before_boarding_starts_is_refused(): void
    {
        $other = $this->flightWithStatus(FlightStatus::CheckinOpen);

        $this->post(route('gate.flights.close', $other))->assertSessionHas('error', 'Boarding is not open');
        $this->assertSame(FlightStatus::CheckinOpen, $other->fresh()->status);
    }

    public function test_checkin_and_reservation_users_get_403(): void
    {
        foreach (['checkin', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->post(route('gate.flights.close', $this->flight))->assertForbidden();
        }

        $this->assertSame(FlightStatus::Boarding, $this->flight->fresh()->status);
    }
}
