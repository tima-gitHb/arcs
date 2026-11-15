<?php

namespace Tests\Feature;

use App\Enums\BagType;
use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Bag;
use App\Models\Coupon;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\BagService;
use App\Services\JourneyService;
use App\Support\Journey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * C15 — PDF bag tag printout (final destination, itinerary, Code 128, reprint).
 */
class C15BagTagTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    private BagService $bags;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => 'ME201',
            'origin' => 'BEY',
            'destination' => 'CDG',
        ]);
        $this->bags = app(BagService::class);
    }

    private function passenger(Flight $flight): Passenger
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight)->create(['pnr' => 'K7M2NP']))->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
            'type' => PassengerType::Adult,
        ]);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger, '12A');

        return $passenger->fresh();
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function connection(): array
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
            'departure_at' => Carbon::parse('2026-11-15 11:30', 'UTC'),
            'arrives_at' => Carbon::parse('2026-11-15 15:15', 'UTC'),
            'checkin_opens_at' => Carbon::parse('2026-11-15 07:00', 'UTC'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 10:45', 'UTC'),
        ]);

        return [$first, $second];
    }

    private function connectingPassenger(): Passenger
    {
        [$first, $second] = $this->connection();
        $reservation = Reservation::factory()->create(['flight_id' => $first->id, 'pnr' => 'C0N3CT']);
        app(JourneyService::class)->addSegments($reservation, new Journey([$first, $second]));

        $passenger = Passenger::factory()->for($reservation)->create([
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
            'type' => PassengerType::Adult,
        ]);
        TravelDocument::factory()->for($passenger)->create();

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

    private function addBag(Passenger $passenger, float $kg, BagType $type = BagType::Standard): Bag
    {
        return $this->bags->add($passenger, $kg, $type, $this->agent);
    }

    private function assertPdfContains(TestResponse $response, string ...$needles): void
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);

        // DomPDF compresses content streams; inflate them so assertions can read the text.
        $haystack = $pdf;
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded === false && strlen($stream) > 2) {
                    $decoded = @gzinflate(substr($stream, 2));
                }
                if (is_string($decoded)) {
                    $haystack .= "\n".$decoded;
                }
            }
        }

        // DejaVu / CID fonts store glyphs as null-padded pairs (C\0D\0G → strip to CDG).
        $haystack = str_replace("\0", '', $haystack);

        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $haystack, "PDF missing: {$needle}");
        }
    }

    public function test_tag_pdf_is_application_pdf_and_contains_destination_flights_tag_and_weight(): void
    {
        $passenger = $this->passenger($this->flight);
        $bag = $this->addBag($passenger, 18.5);

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee(route('checkin.bags.tag', $bag), false)
            ->assertSee(route('checkin.passengers.bag-tags', $passenger), false);

        $this->assertPdfContains(
            $this->get(route('checkin.bags.tag', $bag)),
            'CDG',
            'ME201',
            'BEY',
            $bag->tag_no,
            '18.5',
            'HADDAD',
            'RAMI',
            'K7M2NP',
        );
    }

    public function test_connecting_passenger_tag_shows_CDG_and_both_flights(): void
    {
        $passenger = $this->connectingPassenger();
        $bag = $this->addBag($passenger, 20);

        $this->assertPdfContains(
            $this->get(route('checkin.bags.tag', $bag)),
            'CDG',
            'ME264',
            'ME216',
            'BEY',
            'IST',
            $bag->tag_no,
        );
    }

    public function test_twenty_five_kg_bag_shows_HEAVY(): void
    {
        $passenger = $this->passenger($this->flight);
        $bag = $this->addBag($passenger, 25);

        $this->assertPdfContains($this->get(route('checkin.bags.tag', $bag)), 'HEAVY');
    }

    public function test_stroller_shows_GATE_DELIVERY(): void
    {
        $passenger = $this->passenger($this->flight);
        $bag = $this->addBag($passenger, 8, BagType::Stroller);

        $this->assertPdfContains($this->get(route('checkin.bags.tag', $bag)), 'GATE DELIVERY');
    }

    public function test_reprint_rotates_tag_no_and_writes_audit_row(): void
    {
        $passenger = $this->passenger($this->flight);
        $bag = $this->addBag($passenger, 15);
        $oldTag = $bag->tag_no;

        $this->travelTo(now()->addMinutes(3));
        $response = $this->post(route('checkin.bags.tag.reprint', $bag));
        $this->assertPdfContains($response, 'CDG');

        $bag->refresh();
        $this->assertNotSame($oldTag, $bag->tag_no);
        $this->assertNull(Bag::query()->where('tag_no', $oldTag)->first());
        $this->assertNotNull(Bag::query()->where('tag_no', $bag->tag_no)->first());

        $audit = AuditEntry::query()
            ->where('passenger_id', $passenger->id)
            ->where('field', 'bag_tag_reprint')
            ->sole();
        $this->assertSame($oldTag, $audit->old_value);
        $this->assertSame($bag->tag_no, $audit->new_value);
        $this->assertSame($this->agent->id, $audit->user_id);
        $this->assertTrue($audit->changed_at->equalTo(now()));
    }

    public function test_print_all_tags_returns_one_pdf_with_every_bag(): void
    {
        $passenger = $this->passenger($this->flight);
        $first = $this->addBag($passenger, 12);
        $second = $this->addBag($passenger, 14);

        $this->assertPdfContains(
            $this->get(route('checkin.passengers.bag-tags', $passenger)),
            $first->tag_no,
            $second->tag_no,
            'CDG',
        );
    }

    public function test_gate_and_reservation_users_get_403_on_bag_tag_routes(): void
    {
        $passenger = $this->passenger($this->flight);
        $bag = $this->addBag($passenger, 10);

        foreach ([Role::Gate, Role::Reservation] as $role) {
            $this->actingAsRole($role);

            $this->get(route('checkin.bags.tag', $bag))->assertForbidden();
            $this->post(route('checkin.bags.tag.reprint', $bag))->assertForbidden();
            $this->get(route('checkin.passengers.bag-tags', $passenger))->assertForbidden();
        }
    }
}
