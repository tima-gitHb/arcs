<?php

namespace Tests\Feature\Manager;

use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Payments\CardDetails;
use App\Services\PaymentService;
use App\Support\Money;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * M9 — Payments report (SR-57). Read only.
 *
 * Route these tests expect (added in the next step):
 *   GET manager.payments
 * Filters, the same names as the audit page: flight_id, user_id (taken_by), date (Y-m-d).
 * Menu item: 'Payments' => 'manager.payments'.
 *
 * A payment's flight is COALESCE(its reservation's flight, its passenger's reservation's flight),
 * the same rule as SalesController. The PNR follows the same two links.
 *
 * The view receives $payments (the matching Payment models). Markup:
 *   <tr data-payment="{id}"> one per payment, class "text-red-600" when the status is Failed
 *     <td data-cell="date">       created_at as "d M Y H:i" (Beirut). Not paid_at.
 *     <td data-cell="flight">     flight number
 *     <td data-cell="pnr">        PNR
 *     <td data-cell="passenger">  "First Last", or "Whole booking" when passenger_id is null
 *     <td data-cell="kind">       PaymentKind::label()
 *     <td data-cell="amount">     Money::format
 *     <td data-cell="card">       "{brand} ••••{last4}"
 *     <td data-cell="status">     PaymentStatus::label()
 *     <td data-cell="taken-by">   takenBy name
 *   <span data-total="{PaymentKind value}"> succeeded cents of that kind, one per PaymentKind::cases()
 *   <span data-total="grand">              succeeded cents of every kind
 *   The text "Failed attempts: N ($X)" — N and $X are the failed rows in this same filtered set.
 * Print: a window.print() button with class no-print, and @media print hiding header, nav, .no-print.
 */
class PaymentReportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00:00', 'Asia/Beirut')); // staff are at BEY (B13a)

        $this->agent = User::factory()->create(['role' => Role::Reservation, 'name' => 'Rita Nassar']);
        $this->manager = $this->actingAsRole(Role::Manager);
    }

    private function flight(string $number): Flight
    {
        return Flight::factory()->create(['flight_no' => $number]);
    }

    private function reservation(Flight $flight, string $pnr): Reservation
    {
        return Reservation::factory()->create(['flight_id' => $flight->id, 'pnr' => $pnr]);
    }

    private function charge(
        int $cents,
        PaymentKind $kind,
        ?Reservation $reservation = null,
        ?Passenger $passenger = null,
        ?User $by = null,
        string $card = '4242 4242 4242 4242',
    ): Payment {
        return app(PaymentService::class)->charge(
            new CardDetails($card, '12/30', '123'),
            $cents,
            $kind,
            $by ?? $this->agent,
            $reservation,
            $passenger,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function open(array $query = []): TestResponse
    {
        return $this->get(route('manager.payments', $query))->assertOk();
    }

    /**
     * @return list<int>
     */
    private function ids(TestResponse $response): array
    {
        return $response->viewData('payments')->pluck('id')->all();
    }

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

    private function cell(TestResponse $response, Payment $payment, string $name): ?string
    {
        return $this->text($response, "//tr[@data-payment='{$payment->id}']//*[@data-cell='{$name}']");
    }

    /**
     * @param  array<string, int>  $cents  succeeded cents per kind; a missing kind means 0
     */
    private function assertTotals(TestResponse $response, array $cents, int $grand): void
    {
        foreach (PaymentKind::cases() as $kind) {
            $this->assertSame(
                Money::format($cents[$kind->value] ?? 0),
                $this->text($response, "//*[@data-total='{$kind->value}']"),
                $kind->value,
            );
        }

        $this->assertSame(Money::format($grand), $this->text($response, "//*[@data-total='grand']"));
    }

    public function test_SR57_a_flight_shows_its_payments_totals_whole_booking_and_last_4(): void
    {
        $flight = $this->flight($this->flightNo('201'));
        $booking = $this->reservation($flight, 'ABCD23');
        $lina = Passenger::factory()->for($booking)->create(['first_name' => 'Lina', 'last_name' => 'Karam']);
        $adamReservation = $this->reservation($flight, 'EFGH45');
        $adam = Passenger::factory()->for($adamReservation)->create(['first_name' => 'Adam', 'last_name' => 'Zeidan']);

        $fare = $this->charge(25000, PaymentKind::Fare, reservation: $booking);
        $secondFare = $this->charge(8000, PaymentKind::Fare, reservation: $adamReservation, passenger: $adam);
        // Passenger only: the flight and the PNR come from the passenger's reservation, not reservation_id.
        $excess = $this->charge(5000, PaymentKind::ExcessBaggage, passenger: $lina);

        $other = $this->flight($this->flightNo('305'));
        $this->charge(99900, PaymentKind::Fare, reservation: $this->reservation($other, 'OTHER2'));

        $page = $this->open(['flight_id' => $flight->id]);

        $this->assertEqualsCanonicalizing([$fare->id, $secondFare->id, $excess->id], $this->ids($page));
        $page->assertDontSee('$999.00', false);

        $this->assertSame('10 Nov 2026 10:00', $this->cell($page, $fare, 'date'));
        $this->assertSame($this->flightNo('201'), $this->cell($page, $fare, 'flight'));
        $this->assertSame('ABCD23', $this->cell($page, $fare, 'pnr'));
        $this->assertSame('Whole booking', $this->cell($page, $fare, 'passenger'));
        $this->assertSame('Fare', $this->cell($page, $fare, 'kind'));
        $this->assertSame('$250.00', $this->cell($page, $fare, 'amount'));
        $this->assertSame('Visa ••••4242', $this->cell($page, $fare, 'card'));
        $this->assertSame('Paid', $this->cell($page, $fare, 'status'));
        $this->assertSame('Rita Nassar', $this->cell($page, $fare, 'taken-by'));

        $this->assertSame('EFGH45', $this->cell($page, $secondFare, 'pnr'));
        $this->assertSame('Adam Zeidan', $this->cell($page, $secondFare, 'passenger'));
        $this->assertSame('$80.00', $this->cell($page, $secondFare, 'amount'));

        $this->assertSame($this->flightNo('201'), $this->cell($page, $excess, 'flight'));
        $this->assertSame('ABCD23', $this->cell($page, $excess, 'pnr'));
        $this->assertSame('Lina Karam', $this->cell($page, $excess, 'passenger'));
        $this->assertSame('Excess baggage', $this->cell($page, $excess, 'kind'));
        $this->assertSame('$50.00', $this->cell($page, $excess, 'amount'));

        $this->assertTotals($page, [
            PaymentKind::Fare->value => 33000,
            PaymentKind::ExcessBaggage->value => 5000,
        ], 38000);

        $page->assertSee('••••4242', false);
        $page->assertDontSee('4242424242424242', false);
        $page->assertDontSee('4242 4242 4242 4242', false);
    }

    public function test_SR57_filter_by_agent(): void
    {
        $flight = $this->flight($this->flightNo('201'));
        $reservation = $this->reservation($flight, 'ABCD23');
        $carl = User::factory()->create(['role' => Role::Checkin, 'name' => 'Carl Hobeika']);

        $rita = $this->charge(1000, PaymentKind::Fare, reservation: $reservation);
        $byCarl = $this->charge(2000, PaymentKind::ExcessBaggage, reservation: $reservation, by: $carl);

        $ritaPage = $this->open(['user_id' => $this->agent->id]);
        $this->assertSame([$rita->id], $this->ids($ritaPage));
        $this->assertSame('Rita Nassar', $this->cell($ritaPage, $rita, 'taken-by'));
        $ritaPage->assertDontSee('$20.00', false);

        $carlPage = $this->open(['user_id' => $carl->id]);
        $this->assertSame([$byCarl->id], $this->ids($carlPage));
        $this->assertSame('Carl Hobeika', $this->cell($carlPage, $byCarl, 'taken-by'));
        $carlPage->assertDontSee('$10.00', false);
    }

    public function test_SR57_filter_by_day_uses_beirut_midnight(): void
    {
        $flight = $this->flight($this->flightNo('201'));
        $reservation = $this->reservation($flight, 'ABCD23');

        // 23:50 in Beirut is still 10 November. 00:10 is 11 November in Beirut, but 22:10 UTC on the 10th.
        $this->travelTo(Carbon::parse('2026-11-10 23:50:00', 'Asia/Beirut'));
        $before = $this->charge(1100, PaymentKind::Fare, reservation: $reservation);

        $this->travelTo(Carbon::parse('2026-11-11 00:10:00', 'Asia/Beirut'));
        $after = $this->charge(2200, PaymentKind::Fee, reservation: $reservation);

        $tenth = $this->open(['date' => '2026-11-10']);
        $this->assertSame([$before->id], $this->ids($tenth));
        $this->assertSame('10 Nov 2026 23:50', $this->cell($tenth, $before, 'date'));
        $tenth->assertDontSee('$22.00', false);

        $eleventh = $this->open(['date' => '2026-11-11']);
        $this->assertSame([$after->id], $this->ids($eleventh));
        $this->assertSame('11 Nov 2026 00:10', $this->cell($eleventh, $after, 'date'));
        $this->assertSame('Fee', $this->cell($eleventh, $after, 'kind'));
        $eleventh->assertDontSee('$11.00', false);
    }

    public function test_SR57_failed_payment_is_listed_but_not_totalled(): void
    {
        $flight = $this->flight($this->flightNo('201'));
        $reservation = $this->reservation($flight, 'ABCD23');
        $paid = $this->charge(10000, PaymentKind::Fare, reservation: $reservation);
        $failed = $this->charge(1500, PaymentKind::ExcessBaggage, reservation: $reservation, card: '4000 0000 0000 0002');

        $other = $this->flight($this->flightNo('305'));
        $this->charge(4000, PaymentKind::Fee, reservation: $this->reservation($other, 'OTHER2'), card: '4000 0000 0000 0002');

        $page = $this->open(['flight_id' => $flight->id]);

        $this->assertEqualsCanonicalizing([$paid->id, $failed->id], $this->ids($page));
        $this->assertSame('Paid', $this->cell($page, $paid, 'status'));
        $this->assertSame('Failed', $this->cell($page, $failed, 'status'));
        $this->assertSame('10 Nov 2026 10:00', $this->cell($page, $failed, 'date'));
        $this->assertSame('Visa ••••0002', $this->cell($page, $failed, 'card'));
        $this->assertSame(1, $this->countNodes($page, "//tr[@data-payment='{$failed->id}' and contains(@class, 'text-red-600')]"));
        $this->assertSame(0, $this->countNodes($page, "//tr[@data-payment='{$paid->id}' and contains(@class, 'text-red')]"));

        $this->assertTotals($page, [PaymentKind::Fare->value => 10000], 10000);
        $page->assertSee('Failed attempts: 1 ($15.00)', false);
        $page->assertDontSee('$40.00', false);
        $page->assertDontSee('4000000000000002', false);
    }

    public function test_SR57_upgrade_has_its_own_total(): void
    {
        $flight = $this->flight($this->flightNo('201'));
        $reservation = $this->reservation($flight, 'ABCD23');
        $upgrade = $this->charge(7500, PaymentKind::Upgrade, reservation: $reservation);

        $page = $this->open(['flight_id' => $flight->id]);

        $this->assertSame([$upgrade->id], $this->ids($page));
        $this->assertSame('Cabin upgrade', $this->cell($page, $upgrade, 'kind'));
        $this->assertSame('$75.00', $this->cell($page, $upgrade, 'amount'));
        $this->assertTotals($page, [PaymentKind::Upgrade->value => 7500], 7500);
    }

    public function test_SR57_print_button_and_print_styles_are_on_the_page(): void
    {
        $this->open()
            ->assertSee('Print')
            ->assertSee('window.print()', false)
            ->assertSee('@media print', false)
            ->assertSee('no-print', false);
    }

    public function test_SR57_the_page_uses_a_fixed_number_of_queries(): void
    {
        $flight = $this->flight($this->flightNo('501'));
        $reservation = $this->reservation($flight, 'QUERY2');
        $passenger = Passenger::factory()->for($reservation)->create();

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('manager.payments'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->charge(1000, PaymentKind::Fare, reservation: $reservation);
        $this->charge(2000, PaymentKind::ExcessBaggage, passenger: $passenger);
        $few = $count();

        for ($i = 0; $i < 10; $i++) {
            $this->charge(1000 + $i, PaymentKind::Fare, reservation: $reservation);
            $this->charge(500 + $i, PaymentKind::ExtraBag, passenger: $passenger);
        }

        $this->assertSame($few, $count(), 'The number of queries must not grow with the number of payments.');
    }

    public function test_the_payments_item_is_on_the_manager_menu_only(): void
    {
        $this->assertSame('manager.payments', Role::Manager->menu()['Payments'] ?? null);
        $this->assertTrue(Route::has('manager.payments'));

        $this->get(route('manager.home'))->assertOk()->assertSee('Payments');

        foreach ([Role::Reservation, Role::Checkin, Role::Gate] as $role) {
            $this->assertArrayNotHasKey('Payments', $role->menu());

            $this->actingAsRole($role);
            $this->followingRedirects()->get(route($role->homeRoute()))
                ->assertOk()
                ->assertDontSee('Payments');
        }
    }

    public function test_a_non_manager_cannot_open_the_payments_report(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->get(route('manager.payments'))->assertForbidden();
        }
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get(route('manager.payments'))->assertRedirect(route('login'));
    }
}
