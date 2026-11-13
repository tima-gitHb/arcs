<?php

namespace Tests\Feature\Gate;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\FlightStatus;
use App\Enums\SpecialServiceCode;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\SpecialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class G1ManifestTest extends TestCase
{
    use BuildsGateFlights;
    use RefreshDatabase;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        // flightWithStatus(): check-in 09:00–11:15, departure 12:00 today.
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'Asia/Beirut')); // staff are at BEY (B13a)
        $this->makeAgents();
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME305', 'destination' => 'DXB', 'gate' => 'B7']);
    }

    /**
     * 8 checked in, then 5 of them boarded; plus 2 still Booked.
     */
    private function eightCheckedInFiveBoarded(): array
    {
        $checkedIn = [];
        for ($i = 0; $i < 8; $i++) {
            $checkedIn[] = $this->passengerOn($this->flight);
        }
        $this->passengerOn($this->flight);
        $this->passengerOn($this->flight);

        $this->checkIn(...$checkedIn);
        $this->startBoarding($this->flight);

        $this->travelTo(now()->addMinutes(5)); // boarding at 10:05
        $this->board(...array_slice($checkedIn, 0, 5));

        return $checkedIn;
    }

    public function test_SR11_manifest_and_json_show_checked_in_boarded_and_missing(): void
    {
        $this->eightCheckedInFiveBoarded();

        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSee('ME305')
            ->assertSee('BEY → DXB')
            ->assertSee('12:00')
            ->assertSee('Gate B7')
            ->assertSee('Boarding')
            ->assertSee('data-count="checked_in">8<', false)
            ->assertSee('data-count="boarded">5<', false)
            ->assertSee('data-count="missing">3<', false)
            ->assertSee(route('gate.flights.counts', $this->flight), false);

        // seatPassenger() assigns 1F, 2F, … in creation order (8 checked in, first 5 boarded, 2 booked).
        $this->getJson(route('gate.flights.counts', $this->flight))
            ->assertOk()
            ->assertExactJson([
                'checked_in' => 8,
                'boarded' => 5,
                'missing' => 3,
                'status' => 'Boarding',
                'seats' => [
                    '1F' => 'Boarded',
                    '2F' => 'Boarded',
                    '3F' => 'Boarded',
                    '4F' => 'Boarded',
                    '5F' => 'Boarded',
                    '6F' => 'CheckedIn',
                    '7F' => 'CheckedIn',
                    '8F' => 'CheckedIn',
                    '9F' => 'Booked',
                    '10F' => 'Booked',
                ],
            ]);
    }

    public function test_SR45_each_row_shows_who_checked_in_and_boarded_and_when(): void
    {
        $passengers = $this->eightCheckedInFiveBoarded();
        $boarded = $passengers[0];
        $missing = $passengers[7];

        $response = $this->get(route('gate.flights.show', $this->flight))->assertOk();

        $response->assertSeeInOrder([
            $boarded->last_name,
            'Checked in by Maya Khoury at 10:00',
            'Boarded by Joe Saliba at 10:05',
        ]);

        // A missing passenger: checked in, not boarded.
        preg_match('/<tr[^>]*data-passenger="'.$missing->id.'"[\s\S]*?<\/tr>/', $response->getContent(), $row);
        $this->assertStringContainsString('Checked in by Maya Khoury at 10:00', $row[0]);
        $this->assertStringNotContainsString('Boarded by', $row[0]);
        $this->assertStringContainsString($missing->seatAssignment->seat->seat_no, $row[0]);
    }

    public function test_manifest_shows_special_services_and_bags_to_hand_back_at_the_door(): void
    {
        $passenger = $this->passengerOn($this->flight);
        SpecialService::create(['passenger_id' => $passenger->id, 'type' => SpecialServiceCode::WCHR]);
        Bag::factory()->for($passenger)->create(['tag_no' => 'ME900001', 'type' => BagType::Wheelchair, 'handling' => BagHandling::GateDelivery]);
        Bag::factory()->for($passenger)->create(['tag_no' => 'ME900002', 'type' => BagType::Standard, 'handling' => BagHandling::Hold]);

        $response = $this->get(route('gate.flights.show', $this->flight))->assertOk();

        preg_match('/<tr[^>]*data-passenger="'.$passenger->id.'"[\s\S]*?<\/tr>/', $response->getContent(), $row);
        $this->assertStringContainsString('WCHR', $row[0]);
        $this->assertStringContainsString('ME900001', $row[0]);   // wheelchair: back at the door
        $this->assertStringContainsString('Wheelchair', $row[0]);
        $this->assertStringNotContainsString('ME900002', $row[0]); // hold bag: not a gate item
    }

    public function test_SR41_gate_home_shows_todays_flights_with_a_manifest_link(): void
    {
        Flight::factory()->create(['flight_no' => 'ME900', 'departure_at' => '2026-11-09 18:00']);

        $this->get(route('gate.home'))
            ->assertOk()
            ->assertSee("Today's flights", false)
            ->assertSee('data-role="gate"', false)
            ->assertSee('ME305')
            ->assertSee(route('gate.flights.show', $this->flight), false)
            ->assertDontSee('ME900');
    }

    public function test_a_180_seat_flight_runs_a_fixed_number_of_queries(): void
    {
        // Same mix on both flights (some boarded, some not), so only the size differs.
        $small = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $first = $this->passengerOn($small);
        $this->checkIn($first, $this->passengerOn($small));
        $this->startBoarding($small);
        $this->board($first);

        for ($i = 0; $i < 180; $i++) {
            $this->checkIn($this->passengerOn($this->flight));
        }
        $this->startBoarding($this->flight);
        $this->board(...$this->flight->passengers()->limit(100)->get()->all());

        $count = function (Flight $flight): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('gate.flights.show', $flight))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count($small), $count($this->flight));
    }

    public function test_checkin_and_reservation_users_get_403(): void
    {
        foreach (['checkin', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->get(route('gate.flights.show', $this->flight))->assertForbidden();
            $this->getJson(route('gate.flights.counts', $this->flight))->assertForbidden();
        }
    }
}
