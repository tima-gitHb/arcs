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
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * M15 — filters and the revenue dashboard on the Sales page. Read only.
 *
 * Same route as M12: GET manager.sales. New query parameters, all optional:
 *   from, to   Y-m-d departure dates in the viewer's station time (UTC with no station)
 *   route      one directed pair, value "BEY-CDG" (BEY→CDG is not CDG→BEY)
 *   station    IATA code; flights whose origin is that airport (Flight::departingFrom)
 *   flight     the existing picker (?flight=<id>)
 *
 * Date window: from and to both empty means today at the viewer's station, so a request
 * with no parameters is still today's page. The exception is ?flight= alone: that one
 * flight is shown on any day, as SalesTest already requires. When from and to are set
 * they replace today, including on a chosen flight. route and station always narrow the set.
 * Blank values (the empty options) are the same as leaving the parameter off.
 *
 * Dashboard, succeeded payments only, same COALESCE flight as the per-flight table
 * (reservation, else the passenger's reservation). Summed from that result, no extra query.
 * The per-flight fees cell stays "everything except Fare", so Upgrade is inside it AND
 * has its own dashboard total.
 *
 *   <p data-dates-in>Dates in Asia/Beirut</p>   (the viewer's IANA zone, or UTC)
 *   <input name="from"> <input name="to">
 *   <select name="route">  one <option value="BEY-CDG"> per flown pair, plus value=""
 *   <select name="station"> option value is the IATA code, plus value=""
 *   <section data-dashboard>
 *     <span data-total="Fare|ExtraBag|ExcessBaggage|Fee|Upgrade|extras|grand">$250.00</span>
 *     the text of each span is the amount only
 *   <svg data-chart="ticket-extras"> ticket amount and extras amount as text
 *   <svg data-chart="extras-by-kind"> each extra kind's amount as text
 *   Sports equipment and pets are included in the payment they were charged with.
 */
class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = 'Sports equipment and pets are included in the payment they were charged with.';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 UTC is 12:00 in Beirut, so "today" for the factory manager is 10 Nov 2026.
        $this->travelTo('2026-11-10 10:00:00');
        $this->manager = $this->actingAsRole(Role::Manager);
    }

    // ── Totals ───────────────────────────────────────────────────────────────

    public function test_totals_per_kind_match_the_succeeded_payments(): void
    {
        $flight = $this->flight('BEY', 'CDG', '2026-11-10 14:00:00', 'ME201');
        $this->pay($flight, 25000, PaymentKind::Fare);
        $this->pay($flight, 5000, PaymentKind::ExtraBag);
        $this->pay($flight, 3000, PaymentKind::ExcessBaggage);
        $this->pay($flight, 6000, PaymentKind::Fee);
        // Passenger only, no reservation_id: the flight comes from the second COALESCE branch.
        $this->pay($flight, 8000, PaymentKind::Upgrade, throughPassenger: true);
        $later = $this->flight('BEY', 'DXB', '2026-11-12 14:00:00', 'ME202');
        $this->pay($later, 99900, PaymentKind::Fare);

        $response = $this->sales()->assertOk();

        // The existing per-flight cell is unchanged: fees are still every kind except Fare.
        $this->assertSame('$250.00', $this->revenue($response, $flight, 'fares'));
        $this->assertSame('$220.00', $this->revenue($response, $flight, 'fees'));
        $this->assertSame('$470.00', $this->revenue($response, $flight, 'total'));

        $this->assertSame('$250.00', $this->total($response, 'Fare'));
        $this->assertSame('$50.00', $this->total($response, 'ExtraBag'));
        $this->assertSame('$30.00', $this->total($response, 'ExcessBaggage'));
        $this->assertSame('$60.00', $this->total($response, 'Fee'));
        $this->assertSame('$80.00', $this->total($response, 'Upgrade'));
        $this->assertSame('$220.00', $this->total($response, 'extras'));
        $this->assertSame('$470.00', $this->total($response, 'grand'));
        $this->assertSame(0, $this->countNodes($response, "//section[@data-flight='{$later->flight_no}']"));
        $this->assertSeeNote($response);
        $this->assertSame('Dates in Asia/Beirut', $this->text($response, '//*[@data-dates-in]'));
    }

    public function test_failed_payments_are_never_counted(): void
    {
        $flight = $this->flight('BEY', 'CDG', '2026-11-10 14:00:00', 'ME201');
        $this->pay($flight, 25000, PaymentKind::Fare);
        $this->pay($flight, 11100, PaymentKind::Fare, '4000 0000 0000 0002');
        $this->pay($flight, 22200, PaymentKind::Upgrade, '4000 0000 0000 0002', throughPassenger: true);
        $this->pay($flight, 33300, PaymentKind::ExtraBag, '4000 0000 0000 0002');

        $response = $this->sales()->assertOk();

        $this->assertSame('$250.00', $this->total($response, 'Fare'));
        $this->assertSame('$0.00', $this->total($response, 'ExtraBag'));
        $this->assertSame('$0.00', $this->total($response, 'ExcessBaggage'));
        $this->assertSame('$0.00', $this->total($response, 'Fee'));
        $this->assertSame('$0.00', $this->total($response, 'Upgrade'));
        $this->assertSame('$0.00', $this->total($response, 'extras'));
        $this->assertSame('$250.00', $this->total($response, 'grand'));
        $this->assertStringNotContainsString('$111.00', $response->getContent());
        $this->assertStringNotContainsString('$222.00', $response->getContent());
        $this->assertStringNotContainsString('$333.00', $response->getContent());
    }

    public function test_the_charts_show_the_ticket_and_extras_amounts(): void
    {
        $flight = $this->flight('BEY', 'CDG', '2026-11-10 14:00:00', 'ME201');
        $this->pay($flight, 25000, PaymentKind::Fare);
        $this->pay($flight, 5000, PaymentKind::ExtraBag);
        $this->pay($flight, 3000, PaymentKind::ExcessBaggage);
        $this->pay($flight, 6000, PaymentKind::Fee);
        $this->pay($flight, 8000, PaymentKind::Upgrade, throughPassenger: true);
        $later = $this->flight('BEY', 'DXB', '2026-11-12 14:00:00', 'ME202');
        $this->pay($later, 99900, PaymentKind::Fare);

        $response = $this->sales()->assertOk();

        $ticket = $this->chart($response, 'ticket-extras');
        $this->assertStringContainsString('$250.00', $ticket);
        $this->assertStringContainsString('$220.00', $ticket);
        $this->assertStringNotContainsString('$999.00', $ticket);

        $kinds = $this->chart($response, 'extras-by-kind');
        $this->assertStringContainsString('$50.00', $kinds);
        $this->assertStringContainsString('$30.00', $kinds);
        $this->assertStringContainsString('$60.00', $kinds);
        $this->assertStringContainsString('$80.00', $kinds);
        $this->assertStringNotContainsString('$250.00', $kinds);
        $this->assertStringNotContainsString('$999.00', $kinds);
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    public function test_a_date_range_uses_the_viewers_station_time_including_midnight(): void
    {
        // 21:59 UTC is 23:59 on 10 Nov in Beirut, and still 10 Nov in UTC.
        // 22:00 UTC is 00:00 on 11 Nov in Beirut (the first instant of that day), and still 10 Nov in UTC.
        $night = $this->flight('BEY', 'CDG', '2026-11-10 21:59:00', 'ME211');
        $midnight = $this->flight('BEY', 'CDG', '2026-11-10 22:00:00', 'ME212');
        $this->pay($night, 11100, PaymentKind::Fare);
        $this->pay($midnight, 22200, PaymentKind::Fare);

        $eleventh = $this->sales(['from' => '2026-11-11', 'to' => '2026-11-11'])->assertOk();
        $this->assertSame(1, $this->countNodes($eleventh, "//section[@data-flight='{$midnight->flight_no}']"));
        $this->assertSame(0, $this->countNodes($eleventh, "//section[@data-flight='{$night->flight_no}']"));
        $this->assertSame('$222.00', $this->total($eleventh, 'Fare'));
        $this->assertSame('$222.00', $this->total($eleventh, 'grand'));
        $this->assertSame('Dates in Asia/Beirut', $this->text($eleventh, '//*[@data-dates-in]'));

        $tenth = $this->sales(['from' => '2026-11-10', 'to' => '2026-11-10'])->assertOk();
        $this->assertSame(1, $this->countNodes($tenth, "//section[@data-flight='{$night->flight_no}']"));
        $this->assertSame(0, $this->countNodes($tenth, "//section[@data-flight='{$midnight->flight_no}']"));
        $this->assertSame('$111.00', $this->total($tenth, 'grand'));

        $utc = User::factory()->create(['role' => Role::Manager, 'airport_code' => null]);
        $this->actingAs($utc);

        $utcTenth = $this->sales(['from' => '2026-11-10', 'to' => '2026-11-10'])->assertOk();
        $this->assertSame('Dates in UTC', $this->text($utcTenth, '//*[@data-dates-in]'));
        $this->assertSame(1, $this->countNodes($utcTenth, "//section[@data-flight='{$night->flight_no}']"));
        $this->assertSame(1, $this->countNodes($utcTenth, "//section[@data-flight='{$midnight->flight_no}']"));
        $this->assertSame('$333.00', $this->total($utcTenth, 'grand'));

        $utcEleventh = $this->sales(['from' => '2026-11-11', 'to' => '2026-11-11'])->assertOk();
        $this->assertSame(0, $this->countNodes($utcEleventh, "//section[@data-flight='{$night->flight_no}']"));
        $this->assertSame(0, $this->countNodes($utcEleventh, "//section[@data-flight='{$midnight->flight_no}']"));
        $this->assertSame('$0.00', $this->total($utcEleventh, 'grand'));
    }

    public function test_a_route_filter_keeps_only_that_directed_route(): void
    {
        $beyCdg = $this->flight('BEY', 'CDG', '2026-11-10 14:00:00', 'ME221');
        $cdgBey = $this->flight('CDG', 'BEY', '2026-11-10 16:00:00', 'ME222');
        $later = $this->flight('BEY', 'CDG', '2026-11-12 14:00:00', 'ME223');
        $this->pay($beyCdg, 10000, PaymentKind::Fare);
        $this->pay($cdgBey, 20000, PaymentKind::Fare);
        $this->pay($later, 40000, PaymentKind::Fare);

        $response = $this->sales(['route' => 'BEY-CDG'])->assertOk();

        $this->assertSame(1, $this->countNodes($response, "//section[@data-flight='{$beyCdg->flight_no}']"));
        $this->assertSame(0, $this->countNodes($response, "//section[@data-flight='{$cdgBey->flight_no}']"));
        $this->assertSame(0, $this->countNodes($response, "//section[@data-flight='{$later->flight_no}']"));
        $this->assertSame('$100.00', $this->total($response, 'Fare'));
        $this->assertSame('$0.00', $this->total($response, 'extras'));
        $this->assertSame('$100.00', $this->total($response, 'grand'));

        // One option per flown pair. The return direction is a different option.
        $this->assertSame(1, $this->countNodes($response, '//select[@name="route"]/option[@value="BEY-CDG"]'));
        $this->assertSame(1, $this->countNodes($response, '//select[@name="route"]/option[@value="CDG-BEY"]'));
        $this->assertSame(1, $this->countNodes($response, '//select[@name="route"]/option[@value=""]'));
        $this->assertSame('BEY → CDG', $this->text($response, '//select[@name="route"]/option[@value="BEY-CDG"]'));
    }

    public function test_a_station_filter_keeps_departures_from_that_airport(): void
    {
        $bey = $this->flight('BEY', 'CDG', '2026-11-10 14:00:00', 'ME231');
        $cdg = $this->flight('CDG', 'BEY', '2026-11-10 16:00:00', 'ME232');
        $cdgTomorrow = $this->flight('CDG', 'LHR', '2026-11-11 14:00:00', 'ME233');
        $this->pay($bey, 10000, PaymentKind::Fare);
        $this->pay($cdg, 20000, PaymentKind::Fare);
        $this->pay($cdgTomorrow, 90000, PaymentKind::Fare);

        $response = $this->sales(['station' => 'CDG'])->assertOk();

        $this->assertSame(1, $this->countNodes($response, "//section[@data-flight='{$cdg->flight_no}']"));
        $this->assertSame(0, $this->countNodes($response, "//section[@data-flight='{$bey->flight_no}']"));
        $this->assertSame(0, $this->countNodes($response, "//section[@data-flight='{$cdgTomorrow->flight_no}']"));
        $this->assertSame('$200.00', $this->total($response, 'grand'));
        // Sidebar station switch + sales filter both expose the station options.
        $this->assertSame(2, $this->countNodes($response, '//select[@name="station"]/option[@value="CDG"]'));
        $this->assertSame(2, $this->countNodes($response, '//select[@name="station"]/option[@value=""]'));
    }

    public function test_a_flight_filter_shows_that_flight_on_any_day(): void
    {
        $today = $this->flight('BEY', 'CDG', '2026-11-10 14:00:00', 'ME241');
        $later = $this->flight('BEY', 'DXB', '2026-11-25 14:00:00', 'ME242');
        $this->pay($today, 10000, PaymentKind::Fare);
        $this->pay($later, 40000, PaymentKind::Fare);

        $response = $this->sales(['flight' => $later->id])->assertOk();

        $this->assertSame(1, $this->countNodes($response, "//section[@data-flight='{$later->flight_no}']"));
        $this->assertSame(0, $this->countNodes($response, "//section[@data-flight='{$today->flight_no}']"));
        $this->assertSame('$400.00', $this->total($response, 'Fare'));
        $this->assertSame('$400.00', $this->total($response, 'grand'));
    }

    public function test_filters_combined_keep_only_what_matches_every_one(): void
    {
        $match = $this->flight('BEY', 'CDG', '2026-11-10 14:00:00', 'ME251');
        $sameDay = $this->flight('BEY', 'CDG', '2026-11-10 18:00:00', 'ME252');
        $otherStation = $this->flight('CDG', 'BEY', '2026-11-10 16:00:00', 'ME253');
        $otherDay = $this->flight('BEY', 'CDG', '2026-11-12 14:00:00', 'ME254');
        $this->pay($match, 10000, PaymentKind::Fare);
        $this->pay($match, 2500, PaymentKind::ExtraBag);
        $this->pay($sameDay, 40000, PaymentKind::Fare);
        $this->pay($otherStation, 20000, PaymentKind::Fare);
        $this->pay($otherDay, 30000, PaymentKind::Fare);

        $all = [
            'from' => '2026-11-10',
            'to' => '2026-11-10',
            'route' => 'BEY-CDG',
            'station' => 'BEY',
            'flight' => $match->id,
        ];

        // The date excludes this flight, so the flight id alone must not keep it.
        $wrongDay = $this->sales(array_merge($all, ['from' => '2026-11-12', 'to' => '2026-11-12']))->assertOk();
        $this->assertSame(0, $this->countNodes($wrongDay, "//section[@data-flight='{$match->flight_no}']"));
        $this->assertSame(0, $this->countNodes($wrongDay, "//section[@data-flight='{$otherDay->flight_no}']"));
        $this->assertSame('$0.00', $this->total($wrongDay, 'grand'));

        $response = $this->sales($all)->assertOk();
        $this->assertSame([$match->flight_no], $this->flightNumbers($response));
        $this->assertSame('$100.00', $this->total($response, 'Fare'));
        $this->assertSame('$25.00', $this->total($response, 'ExtraBag'));
        $this->assertSame('$25.00', $this->total($response, 'extras'));
        $this->assertSame('$125.00', $this->total($response, 'grand'));

        $wrongRoute = $this->sales(array_merge($all, ['route' => 'CDG-BEY']))->assertOk();
        $this->assertSame(0, $this->countNodes($wrongRoute, "//section[@data-flight='{$match->flight_no}']"));
        $this->assertSame('$0.00', $this->total($wrongRoute, 'grand'));

        $wrongStation = $this->sales(array_merge($all, ['station' => 'CDG']))->assertOk();
        $this->assertSame(0, $this->countNodes($wrongStation, "//section[@data-flight='{$match->flight_no}']"));
        $this->assertSame('$0.00', $this->total($wrongStation, 'grand'));
    }

    public function test_empty_filters_leave_todays_page_unchanged(): void
    {
        $today = $this->flight('BEY', 'CDG', '2026-11-10 14:00:00', 'ME261');
        $yesterday = $this->flight('BEY', 'DXB', '2026-11-09 14:00:00', 'ME262');
        $tomorrow = $this->flight('CDG', 'BEY', '2026-11-11 14:00:00', 'ME263');
        $this->pay($today, 1500, PaymentKind::Fare);
        $this->pay($yesterday, 8000, PaymentKind::Fare);
        $this->pay($tomorrow, 9000, PaymentKind::Fare);

        $plain = $this->sales()->assertOk();
        $blank = $this->sales([
            'from' => '',
            'to' => '',
            'route' => '',
            'station' => '',
            'flight' => '',
        ])->assertOk();

        // Today's list, the one flight picker, and no "back to today" link. Same with blank fields.
        $this->assertSame(['ME261'], $this->flightNumbers($plain));
        $this->assertSame(['ME261'], $this->flightNumbers($blank));
        $this->assertSame(1, $this->countNodes($plain, "//tr[@data-today-flight='{$today->flight_no}']"));
        $this->assertSame(0, $this->countNodes($plain, "//section[@data-flight='{$yesterday->flight_no}']"));
        $this->assertSame(0, $this->countNodes($plain, "//section[@data-flight='{$tomorrow->flight_no}']"));
        $this->assertSame(1, $this->countNodes($plain, '//select[@name="flight"]'));
        $this->assertSame(0, $this->countNodes($plain, '//*[@data-back-to-today]'));

        $this->assertSame('$15.00', $this->total($plain, 'grand'));
        $this->assertSame('$15.00', $this->total($blank, 'grand'));
        $this->assertSame('$0.00', $this->total($plain, 'extras'));
        $this->assertSeeNote($plain);
    }

    // ── Queries, validation, role ────────────────────────────────────────────

    public function test_the_number_of_queries_does_not_grow_with_the_flights_when_filtered(): void
    {
        $this->flight('BEY', 'CDG', '2026-11-12 10:00:00', 'ME401');
        $one = $this->queryCount(fn () => $this->sales(['from' => '2026-11-12', 'to' => '2026-11-12'])->assertOk());

        foreach (range(2, 7) as $i) {
            $this->flight('BEY', 'CDG', sprintf('2026-11-12 %02d:00:00', 9 + $i), 'ME40'.$i);
        }

        $manyResponse = null;
        $many = $this->queryCount(function () use (&$manyResponse) {
            $manyResponse = $this->sales(['from' => '2026-11-12', 'to' => '2026-11-12'])->assertOk();
        });
        $plain = $this->queryCount(fn () => $this->sales()->assertOk());

        $this->assertCount(7, $manyResponse->viewData('reports'));
        $this->assertSame(1, $this->countNodes($manyResponse, '//section[@data-dashboard]'));
        $this->assertSame($one, $many, "Queries grew from {$one} to {$many}: filtering must not query per flight.");
        $this->assertSame($plain, $many, "Filters added a query ({$plain} without, {$many} with): the totals use the payments query already loaded.");
        $this->assertLessThanOrEqual(10, $many);
    }

    public function test_invalid_filter_values_are_refused(): void
    {
        $this->flight('BEY', 'CDG', '2026-11-10 14:00:00', 'ME271');

        $this->sales(['from' => '10-11-2026'])->assertRedirect()->assertSessionHasErrors('from');
        $this->sales(['to' => '2026-13-40'])->assertRedirect()->assertSessionHasErrors('to');
        $this->sales(['route' => 'XXX-YYY'])->assertRedirect()->assertSessionHasErrors('route');
        $this->sales(['route' => 'BEY'])->assertRedirect()->assertSessionHasErrors('route');
        $this->sales(['route' => 'BEYCDG'])->assertRedirect()->assertSessionHasErrors('route');
        // Both airports exist, but no flight flies CDG→LHR or the return of the flight above.
        $this->sales(['route' => 'CDG-LHR'])->assertRedirect()->assertSessionHasErrors('route');
        $this->sales(['route' => 'CDG-BEY'])->assertRedirect()->assertSessionHasErrors('route');
        $this->sales(['station' => 'ZZZ'])->assertRedirect()->assertSessionHasErrors('station');
        $this->sales(['station' => 'BE'])->assertRedirect()->assertSessionHasErrors('station');
        $this->sales(['flight' => 99999])->assertRedirect()->assertSessionHasErrors('flight');
        $this->sales(['flight' => 'abc'])->assertRedirect()->assertSessionHasErrors('flight');

        $this->assertSame(0, Payment::count());
    }

    public function test_a_non_manager_cannot_open_the_sales_page(): void
    {
        foreach ([Role::Reservation, Role::Checkin, Role::Gate] as $role) {
            $this->actingAsRole($role);

            $this->sales(['from' => '2026-11-10', 'to' => '2026-11-10', 'route' => 'BEY-CDG', 'station' => 'BEY'])
                ->assertForbidden();
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function flight(string $origin, string $destination, string $departureAt, string $number): Flight
    {
        return Flight::factory()->create([
            'flight_no' => $number,
            'origin' => $origin,
            'destination' => $destination,
            'departure_at' => $departureAt,
        ]);
    }

    /**
     * A succeeded charge unless the card is the declining test card.
     * throughPassenger leaves reservation_id null, so the flight is found only via the passenger.
     */
    private function pay(Flight $flight, int $cents, PaymentKind $kind, string $card = '4242 4242 4242 4242', bool $throughPassenger = false): Payment
    {
        $reservation = Reservation::factory()->create(['flight_id' => $flight->id]);
        $passenger = null;

        if ($throughPassenger) {
            $passenger = Passenger::factory()->create(['reservation_id' => $reservation->id]);
            $reservation = null;
        }

        return app(PaymentService::class)->charge(
            new CardDetails($card, '12/30', '123'),
            $cents,
            $kind,
            $this->manager,
            $reservation,
            $passenger,
        );
    }

    private function sales(array $query = []): TestResponse
    {
        return $this->get(route('manager.sales', $query));
    }

    private function queryCount(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return list<string>
     */
    private function flightNumbers(TestResponse $response): array
    {
        return collect($response->viewData('reports'))
            ->map(fn (array $report) => $report['flight']->flight_no)
            ->values()
            ->all();
    }

    private function total(TestResponse $response, string $key): ?string
    {
        $this->assertSame(1, $this->countNodes($response, "//*[@data-total='{$key}']"), "Expected one data-total={$key}.");

        return $this->text($response, "//*[@data-total='{$key}']");
    }

    private function chart(TestResponse $response, string $name): string
    {
        $this->assertSame(1, $this->countNodes($response, "//svg[@data-chart='{$name}']"), "Expected one svg data-chart={$name}.");

        return $this->text($response, "//svg[@data-chart='{$name}']") ?? '';
    }

    private function revenue(TestResponse $response, Flight $flight, string $part): ?string
    {
        return $this->text($response, "//section[@data-flight='{$flight->flight_no}']//*[@data-revenue='{$part}']");
    }

    private function assertSeeNote(TestResponse $response): void
    {
        $response->assertSee(self::NOTE);
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
}
