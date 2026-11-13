<?php

namespace Tests\Feature\Manager;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Models\Aircraft;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AircraftService;
use App\Services\Payments\CardDetails;
use App\Services\PaymentService;
use App\Services\StatusService;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * M12 — Sales per flight (SR-64). Read only.
 *
 * Route these tests expect (added in step 3):
 *   GET manager.sales            today's flights; ?flight=<id> shows that one flight only
 * Menu item: 'Sales per flight' => 'manager.sales'.
 *
 * What a flight's report counts:
 *   sold      = passengers of the flight who are not Cancelled and not Infants, per passengers.cabin_class
 *   capacity  = the aircraft's seats of that class
 *   percent   = sold / capacity, whole percent, half up (12.5 -> 13); null when the class has 0 seats
 *   NotSet    = non-cancelled, non-infant passengers whose cabin_class is null: not in any class row,
 *               but in the overall figure (overall = all of them / all seats)
 *   revenue   = SUCCEEDED payments only, found through payments.reservation_id or payments.passenger_id
 *               (a payment is counted once). Split BY KIND ONLY: Fare = fares;
 *               ExtraBag, ExcessBaggage and Fee = fees. Failed payments never count.
 *
 * The controller gives the view:
 *   reports   list of: flight (Flight),
 *                      classes ['Business'|'Premium'|'Economy' => [sold, capacity, percent]],
 *                      not_set (int), overall [sold, capacity, percent],
 *                      revenue [fares_cents, fees_cents, total_cents]
 *   flights   every flight, for the picker
 *   selected  the chosen Flight, or null
 * Markup (so tests do not depend on wording):
 *   <section data-flight="ME201"> one per report
 *     <tr data-class="Business|Premium|Economy|NotSet|Overall"> with cells data-cell="sold|capacity|load"
 *     load cell has data-overbooked="true" and a text-red class above 100 %
 *     <span data-revenue="fares|fees|total">$250.00</span>
 *   <tr data-today-flight="ME201"> with <a href="...manager.sales?flight=<id>">  (default page only)
 *   <select name="flight"> with an <option> per flight; <a data-back-to-today> when one flight is chosen
 */
class SalesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $agent;

    private int $planes = 0;

    private int $flightNumber = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 10:00:00');

        $this->agent = User::factory()->create(['role' => Role::Reservation]);
        $this->manager = $this->actingAsRole(Role::Manager);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * 30 x 6 = 180 seats by default: Business rows 1-3 (18), Premium rows 4-6 (18), Economy (144).
     */
    private function aircraft(int $rows = 30, int $perRow = 6, int $business = 3, int $premium = 3): Aircraft
    {
        return app(AircraftService::class)->create([
            'registration' => 'OD-S'.str_pad((string) ++$this->planes, 2, '0', STR_PAD_LEFT),
            'model' => 'A320',
            'rows' => $rows,
            'seats_per_row' => $perRow,
            'business_rows' => $business,
            'premium_rows' => $premium,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function flight(?Aircraft $aircraft = null, string $departs = '2026-11-10 14:00:00', array $attributes = []): Flight
    {
        return Flight::factory()->create($attributes + [
            'flight_no' => 'ME'.$this->flightNumber++,
            'aircraft_id' => ($aircraft ?? $this->aircraft())->id,
            'departure_at' => $departs,
            'checkin_opens_at' => date('Y-m-d H:i:s', strtotime($departs) - 10800),
            'checkin_closes_at' => date('Y-m-d H:i:s', strtotime($departs) - 2700),
        ]);
    }

    /**
     * $count Booked passengers of that class on the flight, all on one reservation unless one is given.
     *
     * @return Collection<int, Passenger>
     */
    private function book(Flight $flight, ?CabinClass $cabin, int $count, PassengerType $type = PassengerType::Adult, ?Reservation $reservation = null): Collection
    {
        $reservation ??= Reservation::factory()->create(['flight_id' => $flight->id]);

        return Passenger::factory()->count($count)->for($reservation)->create(['cabin_class' => $cabin, 'type' => $type]);
    }

    private function cancel(Passenger $passenger): void
    {
        app(StatusService::class)->cancel($passenger, $this->agent);
    }

    private function charge(int $cents, PaymentKind $kind, ?Reservation $reservation = null, ?Passenger $passenger = null, string $card = '4242 4242 4242 4242'): Payment
    {
        return app(PaymentService::class)->charge(new CardDetails($card, '12/30', '123'), $cents, $kind, $this->agent, $reservation, $passenger);
    }

    /**
     * The report of one flight, from the data the controller gave the view.
     *
     * @return array<string, mixed>
     */
    private function report(TestResponse $response, Flight $flight): array
    {
        $report = collect($response->viewData('reports'))->first(fn (array $r) => $r['flight']->is($flight));
        $this->assertNotNull($report, "No report for {$flight->flight_no}");

        return $report;
    }

    /**
     * The text of the first node matching the XPath, spaces squeezed; null when there is none.
     */
    private function text(TestResponse $response, string $xpath): ?string
    {
        $nodes = $this->xpath($response)->query($xpath);

        return $nodes->length === 0
            ? null
            : trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $nodes->item(0)->textContent)));
    }

    private function countNodes(TestResponse $response, string $xpath): int
    {
        return $this->xpath($response)->query($xpath)->length;
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    private function cell(TestResponse $response, Flight $flight, string $class, string $cell): ?string
    {
        return $this->text($response, "//section[@data-flight='{$flight->flight_no}']//tr[@data-class='{$class}']//*[@data-cell='{$cell}']");
    }

    private function revenue(TestResponse $response, Flight $flight, string $part): ?string
    {
        return $this->text($response, "//section[@data-flight='{$flight->flight_no}']//*[@data-revenue='{$part}']");
    }

    // ── Seats sold against capacity, load factor ────────────────────────────

    public function test_SR64_the_9_6_72_example_gives_50_33_50_and_48_percent_overall(): void
    {
        // 18 Business, 18 Premium and 144 Economy seats; 9 + 6 + 72 = 87 sold of 180.
        $flight = $this->flight();
        $this->book($flight, CabinClass::Business, 9);
        $this->book($flight, CabinClass::Premium, 6);
        $this->book($flight, CabinClass::Economy, 72);

        $response = $this->get(route('manager.sales'))->assertOk();

        $report = $this->report($response, $flight);
        $this->assertSame(['sold' => 9, 'capacity' => 18, 'percent' => 50], $report['classes']['Business']);
        $this->assertSame(['sold' => 6, 'capacity' => 18, 'percent' => 33], $report['classes']['Premium']);
        $this->assertSame(['sold' => 72, 'capacity' => 144, 'percent' => 50], $report['classes']['Economy']);
        $this->assertSame(['sold' => 87, 'capacity' => 180, 'percent' => 48], $report['overall']);

        // And on the page.
        $this->assertSame('50 %', $this->cell($response, $flight, 'Business', 'load'));
        $this->assertSame('33 %', $this->cell($response, $flight, 'Premium', 'load'));
        $this->assertSame('50 %', $this->cell($response, $flight, 'Economy', 'load'));
        $this->assertSame('48 %', $this->cell($response, $flight, 'Overall', 'load'));
        $this->assertSame('9', $this->cell($response, $flight, 'Business', 'sold'));
        $this->assertSame('18', $this->cell($response, $flight, 'Business', 'capacity'));
        $this->assertSame('87', $this->cell($response, $flight, 'Overall', 'sold'));
        $this->assertSame('180', $this->cell($response, $flight, 'Overall', 'capacity'));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function halfUpCases(): array
    {
        // 8 Economy seats: 1/8 = 12.5, 3/8 = 37.5, 5/8 = 62.5, 7/8 = 87.5 must round UP, not to even.
        return [
            '1 of 8 is 12.5' => [1, 13],
            '2 of 8 is 25' => [2, 25],
            '3 of 8 is 37.5' => [3, 38],
            '5 of 8 is 62.5' => [5, 63],
            '7 of 8 is 87.5' => [7, 88],
            '0 of 8 is 0' => [0, 0],
        ];
    }

    #[DataProvider('halfUpCases')]
    public function test_the_percentage_is_a_whole_number_rounded_half_up(int $sold, int $percent): void
    {
        $flight = $this->flight($this->aircraft(rows: 2, perRow: 4, business: 0, premium: 0));
        $sold > 0 && $this->book($flight, CabinClass::Economy, $sold);

        $response = $this->get(route('manager.sales'));

        $this->assertSame($percent, $this->report($response, $flight)['classes']['Economy']['percent']);
        $this->assertSame("{$percent} %", $this->cell($response, $flight, 'Economy', 'load'));
    }

    public function test_one_third_rounds_to_33_and_two_thirds_to_67(): void
    {
        $flight = $this->flight($this->aircraft(rows: 1, perRow: 3, business: 0, premium: 0));
        $this->book($flight, CabinClass::Economy, 1);
        $other = $this->flight($this->aircraft(rows: 1, perRow: 3, business: 0, premium: 0));
        $this->book($other, CabinClass::Economy, 2);

        $response = $this->get(route('manager.sales'));

        $this->assertSame(33, $this->report($response, $flight)['classes']['Economy']['percent']);
        $this->assertSame(67, $this->report($response, $other)['classes']['Economy']['percent']);
    }

    public function test_a_class_with_no_seats_shows_a_dash_and_does_not_divide_by_zero(): void
    {
        // No Premium rows: capacity 0. A booked Premium passenger must not break the page.
        $flight = $this->flight($this->aircraft(rows: 10, perRow: 6, business: 2, premium: 0));
        $this->book($flight, CabinClass::Premium, 1);
        $this->book($flight, CabinClass::Economy, 6);

        $response = $this->get(route('manager.sales'))->assertOk();

        $report = $this->report($response, $flight);
        $this->assertSame(['sold' => 1, 'capacity' => 0, 'percent' => null], $report['classes']['Premium']);
        $this->assertSame('—', $this->cell($response, $flight, 'Premium', 'load'));
        $this->assertSame('0', $this->cell($response, $flight, 'Premium', 'capacity'));
        // The other classes are not affected.
        $this->assertSame(['sold' => 6, 'capacity' => 48, 'percent' => 13], $report['classes']['Economy']);
    }

    public function test_a_flight_with_nothing_sold_shows_zeros(): void
    {
        $flight = $this->flight();

        $response = $this->get(route('manager.sales'));

        $this->assertSame('0', $this->cell($response, $flight, 'Economy', 'sold'));
        $this->assertSame('0 %', $this->cell($response, $flight, 'Economy', 'load'));
        $this->assertSame('0 %', $this->cell($response, $flight, 'Overall', 'load'));
        $this->assertSame('$0.00', $this->revenue($response, $flight, 'fares'));
        $this->assertSame('$0.00', $this->revenue($response, $flight, 'fees'));
        $this->assertSame('$0.00', $this->revenue($response, $flight, 'total'));
    }

    public function test_cancelled_passengers_are_not_counted(): void
    {
        $flight = $this->flight();
        $passengers = $this->book($flight, CabinClass::Economy, 5);
        $this->cancel($passengers[0]);
        $this->cancel($passengers[1]);

        $report = $this->report($this->get(route('manager.sales')), $flight);

        $this->assertSame(3, $report['classes']['Economy']['sold']);
        // The seats are still there: capacity does not change.
        $this->assertSame(144, $report['classes']['Economy']['capacity']);
        $this->assertSame(3, $report['overall']['sold']);
    }

    public function test_a_cancelled_passenger_without_a_class_is_not_in_the_class_not_set_line(): void
    {
        $flight = $this->flight();
        $passengers = $this->book($flight, null, 2);
        $this->cancel($passengers[0]);

        $report = $this->report($this->get(route('manager.sales')), $flight);

        $this->assertSame(1, $report['not_set']);
    }

    public function test_infants_are_not_counted(): void
    {
        $flight = $this->flight();
        $reservation = Reservation::factory()->create(['flight_id' => $flight->id]);
        $adults = $this->book($flight, CabinClass::Economy, 2, reservation: $reservation);
        // An infant travels in the adult's class and has no seat: it is not a seat sold.
        Passenger::factory()->for($reservation)->create([
            'type' => PassengerType::Infant, 'cabin_class' => CabinClass::Economy, 'accompanying_adult_id' => $adults[0]->id,
        ]);
        // An infant whose class is not set must not show in the class-not-set line either.
        Passenger::factory()->for($reservation)->create([
            'type' => PassengerType::Infant, 'cabin_class' => null, 'accompanying_adult_id' => $adults[1]->id,
        ]);

        $report = $this->report($this->get(route('manager.sales')), $flight);

        $this->assertSame(2, $report['classes']['Economy']['sold']);
        $this->assertSame(0, $report['not_set']);
        $this->assertSame(2, $report['overall']['sold']);
    }

    public function test_boarded_and_no_show_passengers_are_seats_sold(): void
    {
        // The seeded London flight ME110 departed an hour ago: 6 Boarded and 2 NoShow, all Economy.
        // Only Cancelled is left out, so all 8 are sold. OD-MRA has 132 Economy seats: 8 / 132 = 6 %.
        $this->seed(DatabaseSeeder::class);
        $london = Flight::where('flight_no', 'ME110')->firstOrFail();

        // Status on this flight's coupon (B13b).
        $this->assertSame(6, $london->passengers()->wherePivot('status', 'Boarded')->count());
        $this->assertSame(2, $london->passengers()->wherePivot('status', 'NoShow')->count());

        $response = $this->get(route('manager.sales'))->assertOk();

        $report = $this->report($response, $london);
        $this->assertSame(['sold' => 8, 'capacity' => 132, 'percent' => 6], $report['classes']['Economy']);
        $this->assertSame(8, $report['overall']['sold']);
        $this->assertSame('8', $this->cell($response, $london, 'Economy', 'sold'));
    }

    public function test_the_class_not_set_line_is_shown_only_when_there_are_such_passengers(): void
    {
        $flight = $this->flight();
        $this->book($flight, CabinClass::Economy, 2);

        $this->assertSame(0, $this->countNodes($this->get(route('manager.sales')), '//tr[@data-class="NotSet"]'));

        $this->book($flight, null, 1);

        $this->assertSame(1, $this->countNodes($this->get(route('manager.sales')), '//tr[@data-class="NotSet"]'));
    }

    public function test_a_child_is_a_seat_sold(): void
    {
        $flight = $this->flight();
        $this->book($flight, CabinClass::Economy, 1, PassengerType::Child);

        $this->assertSame(1, $this->report($this->get(route('manager.sales')), $flight)['classes']['Economy']['sold']);
    }

    public function test_passengers_of_other_flights_are_not_counted(): void
    {
        $flight = $this->flight();
        $other = $this->flight();
        $this->book($flight, CabinClass::Economy, 2);
        $this->book($other, CabinClass::Economy, 5);

        $response = $this->get(route('manager.sales'));

        $this->assertSame(2, $this->report($response, $flight)['classes']['Economy']['sold']);
        $this->assertSame(5, $this->report($response, $other)['classes']['Economy']['sold']);
    }

    public function test_passengers_without_a_class_get_their_own_line_and_count_in_the_overall_figure(): void
    {
        $flight = $this->flight();
        $this->book($flight, CabinClass::Business, 9);
        $this->book($flight, null, 3);

        $response = $this->get(route('manager.sales'));

        $report = $this->report($response, $flight);
        $this->assertSame(3, $report['not_set']);
        // Not in any class row ...
        $this->assertSame(9, $report['classes']['Business']['sold']);
        $this->assertSame(0, $report['classes']['Economy']['sold']);
        // ... but in the overall figure: 12 of 180 seats = 6.67 %, rounded to 7.
        $this->assertSame(['sold' => 12, 'capacity' => 180, 'percent' => 7], $report['overall']);

        $this->assertSame('3', $this->cell($response, $flight, 'NotSet', 'sold'));
        $this->assertSame('—', $this->cell($response, $flight, 'NotSet', 'load'));
        $this->assertSame('7 %', $this->cell($response, $flight, 'Overall', 'load'));
    }

    public function test_the_overall_figure_is_all_sold_seats_over_all_seats_not_an_average_of_the_classes(): void
    {
        // Business 18/18 = 100 %, Premium 0/18 = 0 %, Economy 0/144 = 0 %: the average of the three
        // would be 33 %, but 18 of 180 seats is 10 %.
        $flight = $this->flight();
        $this->book($flight, CabinClass::Business, 18);

        $this->assertSame(10, $this->report($this->get(route('manager.sales')), $flight)['overall']['percent']);
    }

    public function test_above_100_percent_is_shown_as_it_is_and_marked_red(): void
    {
        $flight = $this->flight($this->aircraft(rows: 2, perRow: 4, business: 0, premium: 0)); // 8 Economy seats
        $this->book($flight, CabinClass::Economy, 10);

        $response = $this->get(route('manager.sales'));

        $this->assertSame(125, $this->report($response, $flight)['classes']['Economy']['percent']);
        $this->assertSame('125 %', $this->cell($response, $flight, 'Economy', 'load'));

        $overbooked = "//section[@data-flight='{$flight->flight_no}']//tr[@data-class='Economy']//*[@data-cell='load'][@data-overbooked='true']";
        $this->assertSame(1, $this->countNodes($response, $overbooked));
        $this->assertStringContainsString('text-red', $this->xpath($response)->query($overbooked)->item(0)->getAttribute('class'));
    }

    public function test_exactly_100_percent_and_below_are_not_marked(): void
    {
        $full = $this->flight($this->aircraft(rows: 2, perRow: 4, business: 0, premium: 0));
        $this->book($full, CabinClass::Economy, 8);
        $half = $this->flight($this->aircraft(rows: 2, perRow: 4, business: 0, premium: 0));
        $this->book($half, CabinClass::Economy, 4);

        $response = $this->get(route('manager.sales'));

        $this->assertSame('100 %', $this->cell($response, $full, 'Economy', 'load'));
        $this->assertSame(0, $this->countNodes($response, '//*[@data-overbooked="true"]'));
    }

    // ── Revenue ─────────────────────────────────────────────────────────────

    public function test_SR64_revenue_is_succeeded_payments_split_into_fares_and_fees_with_a_total(): void
    {
        $flight = $this->flight();
        $reservation = Reservation::factory()->create(['flight_id' => $flight->id]);
        [$first, $second, $third] = $this->book($flight, CabinClass::Economy, 3, reservation: $reservation);

        // Linked through the reservation (booking) ...
        $this->charge(25000, PaymentKind::Fare, reservation: $reservation);
        // ... and through the passenger (check-in extras).
        $this->charge(5000, PaymentKind::ExtraBag, passenger: $first);
        $this->charge(3000, PaymentKind::ExcessBaggage, passenger: $second);
        $this->charge(6000, PaymentKind::Fee, reservation: $reservation);

        $response = $this->get(route('manager.sales'));

        $this->assertSame(
            ['fares_cents' => 25000, 'fees_cents' => 14000, 'total_cents' => 39000],
            $this->report($response, $flight)['revenue'],
        );
        $this->assertSame('$250.00', $this->revenue($response, $flight, 'fares'));
        $this->assertSame('$140.00', $this->revenue($response, $flight, 'fees'));
        $this->assertSame('$390.00', $this->revenue($response, $flight, 'total'));
    }

    public function test_a_payment_linked_by_both_reservation_and_passenger_is_counted_once(): void
    {
        $flight = $this->flight();
        $reservation = Reservation::factory()->create(['flight_id' => $flight->id]);
        $passengers = $this->book($flight, CabinClass::Economy, 3, reservation: $reservation);

        // Both ids set, and the reservation has 3 passengers: a careless join would count it 3 or 6 times.
        $this->charge(10000, PaymentKind::Fare, reservation: $reservation, passenger: $passengers[0]);
        // One payment on a reservation with several passengers: still once.
        $this->charge(20000, PaymentKind::Fare, reservation: $reservation);

        $this->assertSame(
            ['fares_cents' => 30000, 'fees_cents' => 0, 'total_cents' => 30000],
            $this->report($this->get(route('manager.sales')), $flight)['revenue'],
        );
    }

    public function test_the_split_is_by_kind_only_not_by_how_the_payment_is_linked(): void
    {
        // Fare = fares; ExtraBag, ExcessBaggage and Fee = fees. A Fare paid at check-in (linked to the
        // passenger) is still a fare; a Fee paid at booking (linked to the reservation) is still a fee.
        // If a Fare payment also contains fees, the page cannot tell: it follows the kind.
        $flight = $this->flight();
        $reservation = Reservation::factory()->create(['flight_id' => $flight->id]);
        $passenger = $this->book($flight, CabinClass::Economy, 1, reservation: $reservation)->first();

        $this->charge(2000, PaymentKind::Fare, passenger: $passenger);
        $this->charge(700, PaymentKind::Fee, reservation: $reservation);
        $this->charge(300, PaymentKind::ExtraBag, reservation: $reservation);
        $this->charge(100, PaymentKind::ExcessBaggage, reservation: $reservation);

        $this->assertSame(
            ['fares_cents' => 2000, 'fees_cents' => 1100, 'total_cents' => 3100],
            $this->report($this->get(route('manager.sales')), $flight)['revenue'],
        );
    }

    public function test_failed_payments_are_never_counted(): void
    {
        $flight = $this->flight();
        $reservation = Reservation::factory()->create(['flight_id' => $flight->id]);
        $passenger = $this->book($flight, CabinClass::Economy, 1, reservation: $reservation)->first();

        $this->charge(25000, PaymentKind::Fare, reservation: $reservation);
        $declined = $this->charge(99900, PaymentKind::Fare, reservation: $reservation, card: '4000 0000 0000 0002');
        $declinedFee = $this->charge(8800, PaymentKind::ExtraBag, passenger: $passenger, card: '4000 0000 0000 0002');

        // The decline really was saved as Failed, so the page is ignoring it, not missing it.
        $this->assertSame(2, Payment::where('status', 'Failed')->count());
        $this->assertSame('Failed', $declined->status->value);
        $this->assertSame('Failed', $declinedFee->status->value);

        $response = $this->get(route('manager.sales'));

        $this->assertSame(
            ['fares_cents' => 25000, 'fees_cents' => 0, 'total_cents' => 25000],
            $this->report($response, $flight)['revenue'],
        );
        $this->assertSame('$250.00', $this->revenue($response, $flight, 'total'));
    }

    public function test_payments_of_other_flights_are_not_counted(): void
    {
        $flight = $this->flight();
        $other = $this->flight();
        $this->charge(25000, PaymentKind::Fare, reservation: Reservation::factory()->create(['flight_id' => $flight->id]));
        $otherPassenger = $this->book($other, CabinClass::Economy, 1)->first();
        $this->charge(7700, PaymentKind::ExtraBag, passenger: $otherPassenger);

        $response = $this->get(route('manager.sales'));

        $this->assertSame(25000, $this->report($response, $flight)['revenue']['total_cents']);
        $this->assertSame(7700, $this->report($response, $other)['revenue']['total_cents']);
        $this->assertSame(0, $this->report($response, $other)['revenue']['fares_cents']);
    }

    public function test_revenue_is_shown_with_the_money_formatter(): void
    {
        $flight = $this->flight();
        $this->charge(123456, PaymentKind::Fare, reservation: Reservation::factory()->create(['flight_id' => $flight->id]));

        $this->assertSame('$1,234.56', $this->revenue($this->get(route('manager.sales')), $flight, 'fares'));
    }

    // ── Which flights: today by default, any flight through the picker ──────

    public function test_the_default_page_shows_only_todays_flights_in_departure_order(): void
    {
        $evening = $this->flight(departs: '2026-11-10 20:00:00', attributes: ['flight_no' => 'ME900']);
        $morning = $this->flight(departs: '2026-11-10 08:00:00', attributes: ['flight_no' => 'ME901']);
        $yesterday = $this->flight(departs: '2026-11-09 14:00:00', attributes: ['flight_no' => 'ME902']);
        $tomorrow = $this->flight(departs: '2026-11-11 14:00:00', attributes: ['flight_no' => 'ME903']);

        $response = $this->get(route('manager.sales'))->assertOk();

        $this->assertSame(
            ['ME901', 'ME900'],
            collect($response->viewData('reports'))->map(fn (array $r) => $r['flight']->flight_no)->all(),
        );
        $this->assertSame(2, $this->countNodes($response, '//section[@data-flight]'));
        $this->assertSame(0, $this->countNodes($response, '//section[@data-flight="ME902"]'));
        $this->assertSame(0, $this->countNodes($response, '//section[@data-flight="ME903"]'));
        $this->assertNull($response->viewData('selected'));
    }

    public function test_the_default_page_links_each_of_todays_flights_to_its_sales_page(): void
    {
        $today = $this->flight(attributes: ['flight_no' => 'ME910']);
        $this->flight(departs: '2026-11-11 14:00:00', attributes: ['flight_no' => 'ME911']);

        $response = $this->get(route('manager.sales'));

        $link = "//tr[@data-today-flight='ME910']//a";
        $this->assertSame(1, $this->countNodes($response, $link));
        $this->assertSame(
            route('manager.sales', ['flight' => $today->id]),
            html_entity_decode($this->xpath($response)->query($link)->item(0)->getAttribute('href')),
        );
        $this->assertSame(0, $this->countNodes($response, "//tr[@data-today-flight='ME911']"));
    }

    public function test_the_picker_lists_every_flight_not_only_todays(): void
    {
        $this->flight(departs: '2026-11-09 14:00:00', attributes: ['flight_no' => 'ME920']);
        $this->flight(departs: '2026-11-10 14:00:00', attributes: ['flight_no' => 'ME921']);
        $this->flight(departs: '2026-11-25 14:00:00', attributes: ['flight_no' => 'ME922']);

        $response = $this->get(route('manager.sales'));

        $this->assertSame(1, $this->countNodes($response, '//select[@name="flight"]'));
        $this->assertStringContainsString('ME920', $this->text($response, '//select[@name="flight"]'));
        $this->assertStringContainsString('ME921', $this->text($response, '//select[@name="flight"]'));
        $this->assertStringContainsString('ME922', $this->text($response, '//select[@name="flight"]'));
        $this->assertSame(3, $this->countNodes($response, '//select[@name="flight"]/option[@value!=""]'));
    }

    public function test_choosing_a_flight_shows_that_flight_only_with_a_back_to_today_link(): void
    {
        $today = $this->flight(attributes: ['flight_no' => 'ME930']);
        $chosen = $this->flight(departs: '2026-11-25 14:00:00', attributes: ['flight_no' => 'ME931']);
        $this->book($chosen, CabinClass::Business, 9);

        $response = $this->get(route('manager.sales', ['flight' => $chosen->id]))->assertOk();

        $this->assertTrue($response->viewData('selected')->is($chosen));
        $this->assertCount(1, $response->viewData('reports'));
        $this->assertSame(1, $this->countNodes($response, '//section[@data-flight]'));
        $this->assertSame(1, $this->countNodes($response, '//section[@data-flight="ME931"]'));
        $this->assertSame('50 %', $this->cell($response, $chosen, 'Business', 'load'));

        $back = '//a[@data-back-to-today]';
        $this->assertSame(1, $this->countNodes($response, $back));
        $this->assertSame(route('manager.sales'), $this->xpath($response)->query($back)->item(0)->getAttribute('href'));
        $this->assertStringContainsString('Back to today', $this->text($response, $back));
    }

    public function test_the_default_page_has_no_back_to_today_link(): void
    {
        $this->flight();

        $this->assertSame(0, $this->countNodes($this->get(route('manager.sales')), '//a[@data-back-to-today]'));
    }

    public function test_a_flight_from_the_past_can_be_chosen(): void
    {
        $past = $this->flight(departs: '2026-10-01 14:00:00');
        $this->book($past, CabinClass::Economy, 2);

        $response = $this->get(route('manager.sales', ['flight' => $past->id]))->assertOk();

        $this->assertSame(2, $this->report($response, $past)['classes']['Economy']['sold']);
    }

    public function test_an_unknown_or_invalid_flight_is_refused(): void
    {
        $this->get(route('manager.sales', ['flight' => 99999]))->assertSessionHasErrors('flight');
        $this->get(route('manager.sales', ['flight' => 'abc']))->assertSessionHasErrors('flight');
    }

    public function test_with_no_flights_today_the_page_says_so(): void
    {
        $this->flight(departs: '2026-11-11 14:00:00');

        $this->get(route('manager.sales'))
            ->assertOk()
            ->assertSee('No flights today');
    }

    // ── Fixed number of queries ─────────────────────────────────────────────

    public function test_the_number_of_queries_does_not_depend_on_the_number_of_flights_or_passengers(): void
    {
        $this->flight();

        DB::enableQueryLog();
        $this->get(route('manager.sales'))->assertOk();
        $few = count(DB::getQueryLog());

        // Six more flights today, each with passengers of every class, bags of payments of every kind.
        foreach (range(1, 6) as $i) {
            $flight = $this->flight(departs: '2026-11-10 1'.$i.':00:00');
            $reservation = Reservation::factory()->create(['flight_id' => $flight->id]);
            $this->book($flight, CabinClass::Business, 2, reservation: $reservation);
            $passengers = $this->book($flight, CabinClass::Economy, 3, reservation: $reservation);
            $this->book($flight, null, 1);
            $this->charge(1000 * $i, PaymentKind::Fare, reservation: $reservation);
            $this->charge(100 * $i, PaymentKind::ExtraBag, passenger: $passengers[0]);
        }

        DB::flushQueryLog();
        $response = $this->get(route('manager.sales'))->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(7, $response->viewData('reports'));
        $this->assertSame($few, $many, "Queries grew from {$few} to {$many}: the page must not query per flight.");
        $this->assertLessThanOrEqual(10, $many);
    }

    // ── Read only, menu and access ──────────────────────────────────────────

    public function test_the_page_is_read_only(): void
    {
        $flight = $this->flight();
        $this->book($flight, CabinClass::Economy, 2);
        $this->charge(25000, PaymentKind::Fare, reservation: Reservation::factory()->create(['flight_id' => $flight->id]));
        $before = [Passenger::count(), Payment::count(), DB::table('audit_log')->count(), Flight::count()];

        $this->get(route('manager.sales'))->assertOk();
        $this->get(route('manager.sales', ['flight' => $flight->id]))->assertOk();

        $this->assertSame($before, [Passenger::count(), Payment::count(), DB::table('audit_log')->count(), Flight::count()]);
        // There is nothing to submit: only GET exists on this route.
        $this->post(route('manager.sales'))->assertStatus(405);
        $this->put(route('manager.sales'))->assertStatus(405);
    }

    public function test_the_manager_menu_has_the_sales_item(): void
    {
        $this->assertSame('manager.sales', Role::Manager->menu()['Sales per flight'] ?? null);
        $this->assertTrue(Route::has('manager.sales'));

        $this->get(route('manager.home'))->assertOk()->assertSee('Sales per flight');
    }

    public function test_other_roles_do_not_see_the_sales_item(): void
    {
        foreach ([Role::Reservation, Role::Checkin, Role::Gate] as $role) {
            $this->assertArrayNotHasKey('Sales per flight', $role->menu());

            $this->actingAsRole($role);
            $this->followingRedirects()->get(route($role->homeRoute()))
                ->assertOk()
                ->assertDontSee('Sales per flight');
        }
    }

    public function test_non_managers_get_403(): void
    {
        $flight = $this->flight();

        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->get(route('manager.sales'))->assertForbidden();
            $this->get(route('manager.sales', ['flight' => $flight->id]))->assertForbidden();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get(route('manager.sales'))->assertRedirect(route('login'));
    }
}
