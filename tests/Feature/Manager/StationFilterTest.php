<?php

namespace Tests\Feature\Manager;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Aircraft;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * M17 — one station filter on the manager pages, remembered in the session.
 *
 * Session key: manager_station. Default is All stations, not the manager's own airport.
 *   ?station=CDG   saves CDG and keeps flights whose origin is CDG (Flight::departingFrom)
 *   ?station=      clears it
 *   the key absent reads whatever was saved
 *
 * The indicator, only while a station is active: "Station: CDG · Show all stations".
 * The link is "Show all stations" and its href sends an empty station parameter.
 *
 * Audit while CDG is selected:
 *   a row with flight_id uses that flight's origin (even when the passenger's reservation is elsewhere)
 *   a passenger row with no flight_id uses the reservation flight's origin
 *   an airport row shows only when the field starts with "airport CDG "
 *   fare and fee rows, and any other row with neither id, are hidden
 * All stations shows every row.
 *
 * A flight created from another origin keeps the usual success text and adds
 * "It departs from BEY, not CDG, so this list does not show it."
 * <x-todays-flights> without a station attribute is unchanged. The dashboard passes the session station.
 */
class StationFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The manager works at BEY. "Today" is 10 Nov there. The filter must not default to BEY.
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'Asia/Beirut'));
        $this->actingAsRole(Role::Manager);
    }

    public function test_cdg_keeps_only_cdg_departures_on_each_page(): void
    {
        [$cdg, $bey] = $this->pair();
        $this->passengers($cdg, $bey);
        $this->payments($cdg, $bey);

        $dashboard = $this->open('manager.dashboard', 'CDG');
        $this->assertSame('CDG', session('manager_station'));
        $this->assertSame(1, $this->countNodes($dashboard, '//*[@data-role="manager"]//td[contains(., "ME801")]'));
        $this->assertSame(0, $this->countNodes($dashboard, '//*[@data-role="manager"]//td[contains(., "ME802")]'));
        $this->assertSame(1, $this->countNodes($dashboard, '//h2[contains(., "ME801")]'));
        $this->assertSame(0, $this->countNodes($dashboard, '//h2[contains(., "ME802")]'));
        $this->assertSeeIndicator($dashboard, 'CDG');

        $flights = $this->open('manager.flights');
        $flights->assertSee('ME801')->assertDontSee('ME802');
        $this->assertSeeIndicator($flights, 'CDG');

        $lists = $this->open('manager.home', null, ['flight' => $bey->id]);
        $lists->assertDontSee('ME802')->assertDontSee('Rami Bey');
        $this->open('manager.home', null, ['flight' => $cdg->id])
            ->assertSee('ME801')
            ->assertSee('Nora Cdg')
            ->assertDontSee('Rami Bey');

        $audit = $this->open('manager.audit');
        $audit->assertSee('GateCdgOnly')
            ->assertSee('FromCdgReservation')
            ->assertSee('Lea Mixed')
            ->assertSee('airport CDG minutes')
            ->assertDontSee('GateBeyOnly')
            ->assertDontSee('FromBeyReservation')
            ->assertDontSee('airport BEY city')
            ->assertDontSee('airport CDGX minutes')
            ->assertDontSee('fare Economy Adult')
            ->assertDontSee('fare BEY-CDG Economy Adult')
            ->assertDontSee('fee Pet');

        $payments = $this->open('manager.payments');
        $this->assertSame(1, $this->countNodes($payments, '//tr[@data-payment]'));
        $payments->assertSee('ME801')->assertDontSee('ME802');

        $sales = $this->open('manager.sales');
        $this->assertSame(1, $this->countNodes($sales, '//section[@data-flight="ME801"]'));
        $this->assertSame(0, $this->countNodes($sales, '//section[@data-flight="ME802"]'));
        $this->assertSame(0, $this->countNodes($sales, '//select[@name="flight"]/option[contains(., "ME802")]'));
    }

    public function test_all_stations_is_the_default_and_shows_every_row(): void
    {
        [$cdg, $bey] = $this->pair();
        $this->passengers($cdg, $bey);
        $this->payments($cdg, $bey);

        $this->assertNull(session('manager_station'));

        $dashboard = $this->get(route('manager.dashboard'))->assertOk();
        $dashboard->assertSee('ME801')->assertSee('ME802');
        $dashboard->assertDontSee('Show all stations', false);

        $this->get(route('manager.audit'))->assertOk()
            ->assertSee('GateCdgOnly')
            ->assertSee('GateBeyOnly')
            ->assertSee('FromCdgReservation')
            ->assertSee('FromBeyReservation')
            ->assertSee('Lea Mixed')
            ->assertSee('airport CDG minutes')
            ->assertSee('airport BEY city')
            ->assertSee('fare Economy Adult')
            ->assertSee('fee Pet');

        $this->assertSame(2, $this->countNodes($this->get(route('manager.payments'))->assertOk(), '//tr[@data-payment]'));
    }

    public function test_each_page_has_a_station_select_preselected_from_the_session(): void
    {
        $this->open('manager.dashboard', 'CDG');

        foreach (['manager.dashboard', 'manager.flights', 'manager.home', 'manager.audit', 'manager.payments'] as $name) {
            $page = $this->get(route($name))->assertOk();

            $this->assertSame(1, $this->countNodes($page, '//select[@name="station"]'));
            $this->assertSame(1, $this->countNodes($page, '//select[@name="station"]/option[@value="" and normalize-space()="All stations"]'));
            $this->assertSame(1, $this->countNodes($page, '//select[@name="station"]/option[@value="CDG" and @selected]'));
            $this->assertSame(1, $this->countNodes($page, '//select[@name="station"]/option[@value="BEY" and not(@selected)]'));
            $this->assertSame(1, $this->countNodes($page, '//select[@name="station"][contains(@onchange, "submit")]'));
        }
    }

    public function test_the_dashboard_lists_every_stations_flights_when_none_is_saved(): void
    {
        $this->pair();
        $this->assertNull(session('manager_station'));

        $page = $this->get(route('manager.dashboard'))->assertOk();

        // The manager works at BEY. With nothing saved, today's CDG departure is still listed.
        $this->assertSame(1, $this->countNodes($page, '//*[@data-role="manager"]//td[contains(., "ME801")]'));
        $this->assertSame(1, $this->countNodes($page, '//*[@data-role="manager"]//td[contains(., "ME802")]'));
    }

    public function test_the_choice_is_remembered_and_show_all_stations_clears_it(): void
    {
        $this->pair();

        $this->open('manager.dashboard', 'CDG')->assertDontSee('ME802');
        $this->assertSame('CDG', session('manager_station'));

        $flights = $this->get(route('manager.flights'))->assertOk();
        $flights->assertSee('ME801')->assertDontSee('ME802');
        $this->assertSeeIndicator($flights, 'CDG');

        $href = $this->clearHref($flights);
        $query = [];
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        $this->assertSame('', $query['station'] ?? null);

        $cleared = $this->get(parse_url($href, PHP_URL_PATH).'?'.parse_url($href, PHP_URL_QUERY))->assertOk();
        $cleared->assertSee('ME801')->assertSee('ME802')->assertDontSee('Show all stations', false);
        $this->assertNull(session('manager_station'));
    }

    public function test_creating_a_flight_from_another_station_says_so(): void
    {
        $this->open('manager.flights', 'CDG');
        $aircraft = Aircraft::factory()->create();

        $this->post(route('manager.flights.store'), $this->flightForm($aircraft, 'ME900', 'BEY', 'CDG'))
            ->assertRedirect(route('manager.flights'))
            ->assertSessionHas('success', 'Flight ME900 created. It departs from BEY, not CDG, so this list does not show it.');

        $hidden = $this->get(route('manager.flights'))->assertOk();
        // The success message names the flight. The list itself does not.
        $this->assertSame(0, $this->countNodes($hidden, '//table//td[contains(., "ME900")]'));

        $this->post(route('manager.flights.store'), $this->flightForm($aircraft, 'ME901', 'CDG', 'LHR'))
            ->assertRedirect(route('manager.flights'))
            ->assertSessionHas('success', 'Flight ME901 created.');

        $this->get(route('manager.flights'))->assertOk()->assertSee('ME901')->assertDontSee('ME900');
    }

    public function test_todays_flights_without_a_station_attribute_is_unchanged(): void
    {
        $this->pair();

        $this->blade('<x-todays-flights role="manager" />')
            ->assertSee('ME801')
            ->assertSee('ME802');

        $this->actingAsRole(Role::Checkin);

        $this->blade('<x-todays-flights role="checkin" />')
            ->assertSee('ME802')
            ->assertDontSee('ME801');
    }

    public function test_an_invalid_station_is_refused(): void
    {
        foreach (['manager.dashboard', 'manager.flights', 'manager.home', 'manager.audit', 'manager.payments', 'manager.sales'] as $name) {
            $this->get(route($name, ['station' => 'ZZZ']))
                ->assertRedirect()
                ->assertSessionHasErrors('station');
        }
    }

    public function test_a_station_filter_does_not_add_a_query(): void
    {
        $this->pair();

        foreach (['manager.dashboard', 'manager.flights', 'manager.home', 'manager.audit', 'manager.payments', 'manager.sales'] as $name) {
            session()->forget('manager_station');
            $plain = $this->queryCount(fn () => $this->get(route($name))->assertOk());
            $filtered = $this->queryCount(fn () => $this->get(route($name, ['station' => 'CDG']))->assertOk());

            $this->assertSame($plain, $filtered, "{$name} ran {$filtered} queries with a station and {$plain} without.");
        }
    }

    public function test_a_non_manager_cannot_open_the_filtered_pages(): void
    {
        foreach ([Role::Reservation, Role::Checkin, Role::Gate] as $role) {
            $this->actingAsRole($role);

            foreach (['manager.dashboard', 'manager.flights', 'manager.home', 'manager.audit', 'manager.payments', 'manager.sales'] as $name) {
                $this->get(route($name, ['station' => 'CDG']))->assertForbidden();
            }
        }
    }

    /**
     * @return array{0: Flight, 1: Flight}
     */
    private function pair(): array
    {
        $cdg = Flight::factory()->create([
            'flight_no' => 'ME801',
            'origin' => 'CDG',
            'destination' => 'BEY',
            'departure_at' => now()->addHours(2),
        ]);
        $bey = Flight::factory()->create([
            'flight_no' => 'ME802',
            'origin' => 'BEY',
            'destination' => 'CDG',
            'departure_at' => now()->addHours(3),
        ]);

        return [$cdg, $bey];
    }

    private function passengers(Flight $cdg, Flight $bey): void
    {
        Passenger::factory()->for(Reservation::factory()->create(['flight_id' => $cdg->id]))->create([
            'first_name' => 'Nora',
            'last_name' => 'Cdg',
        ]);
        Passenger::factory()->for(Reservation::factory()->create(['flight_id' => $bey->id]))->create([
            'first_name' => 'Rami',
            'last_name' => 'Bey',
        ]);

        $this->auditRows($cdg, $bey);
    }

    private function auditRows(Flight $cdg, Flight $bey): void
    {
        $onCdg = Passenger::where('last_name', 'Cdg')->first()
            ?? Passenger::factory()->for(Reservation::factory()->create(['flight_id' => $cdg->id]))->create(['last_name' => 'Cdg']);
        $onBey = Passenger::where('last_name', 'Bey')->first()
            ?? Passenger::factory()->for(Reservation::factory()->create(['flight_id' => $bey->id]))->create(['last_name' => 'Bey']);
        $mixed = Passenger::factory()->for($onBey->reservation)->create([
            'first_name' => 'Lea',
            'last_name' => 'Mixed',
        ]);

        $this->audit(['flight_id' => $cdg->id, 'field' => 'gate', 'new_value' => 'GateCdgOnly']);
        $this->audit(['flight_id' => $bey->id, 'field' => 'gate', 'new_value' => 'GateBeyOnly']);
        $this->audit(['passenger_id' => $onCdg->id, 'field' => 'first_name', 'new_value' => 'FromCdgReservation']);
        $this->audit(['passenger_id' => $onBey->id, 'field' => 'first_name', 'new_value' => 'FromBeyReservation']);
        // The coupon flight is CDG. The reservation flight is BEY. The row follows the coupon flight.
        $this->audit(['passenger_id' => $mixed->id, 'flight_id' => $cdg->id, 'field' => 'status', 'new_value' => 'CheckedIn']);
        $this->audit(['field' => 'airport CDG minutes', 'old_value' => '45', 'new_value' => '60']);
        $this->audit(['field' => 'airport BEY city', 'old_value' => 'Beirut', 'new_value' => 'Beyrouth']);
        $this->audit(['field' => 'airport CDGX minutes', 'old_value' => '45', 'new_value' => '50']);
        $this->audit(['field' => 'fare Economy Adult', 'old_value' => '$250.00', 'new_value' => '$260.00']);
        $this->audit(['field' => 'fare BEY-CDG Economy Adult', 'old_value' => '$180.00', 'new_value' => '$190.00']);
        $this->audit(['field' => 'fee Pet', 'old_value' => '$60.00', 'new_value' => '$70.00']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function audit(array $attributes): void
    {
        AuditEntry::create($attributes + [
            'old_value' => null,
            'user_id' => auth()->id(),
            'changed_at' => now(),
        ]);
    }

    private function payments(Flight $cdg, Flight $bey): void
    {
        $manager = auth()->user();
        $onCdg = Reservation::where('flight_id', $cdg->id)->first()
            ?? Reservation::factory()->create(['flight_id' => $cdg->id]);
        $beyPassenger = Passenger::where('last_name', 'Bey')->first();

        Payment::create([
            'reservation_id' => $onCdg->id,
            'kind' => PaymentKind::Fare,
            'amount_cents' => 10000,
            'currency' => 'USD',
            'method' => 'card',
            'card_brand' => 'Visa',
            'card_last4' => '4242',
            'gateway_reference' => 'pay-cdg',
            'status' => PaymentStatus::Succeeded,
            'taken_by' => $manager->id,
            'paid_at' => now(),
        ]);
        Payment::create([
            'passenger_id' => $beyPassenger->id,
            'kind' => PaymentKind::ExtraBag,
            'amount_cents' => 5000,
            'currency' => 'USD',
            'method' => 'card',
            'card_brand' => 'Visa',
            'card_last4' => '4242',
            'gateway_reference' => 'pay-bey',
            'status' => PaymentStatus::Succeeded,
            'taken_by' => $manager->id,
            'paid_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function flightForm(Aircraft $aircraft, string $number, string $origin, string $destination): array
    {
        return [
            'flight_no' => $number,
            'origin' => $origin,
            'destination' => $destination,
            'aircraft_id' => $aircraft->id,
            'departure_at' => '2026-11-11T14:00',
            'checkin_opens_at' => '2026-11-11T11:00',
            'checkin_closes_at' => '2026-11-11T13:15',
            'gate' => 'B4',
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function open(string $name, ?string $station = null, array $extra = []): TestResponse
    {
        $query = $extra;
        if ($station !== null) {
            $query['station'] = $station;
        }

        return $this->get(route($name, $query))->assertOk();
    }

    private function assertSeeIndicator(TestResponse $response, string $code): void
    {
        $response->assertSee('Station: '.$code.' ·', false);
        $response->assertSee('Show all stations', false);
        $this->clearHref($response);
    }

    private function clearHref(TestResponse $response): string
    {
        $hrefs = $this->nodes($response, "//a[normalize-space()='Show all stations']/@href");
        $this->assertCount(1, $hrefs);

        return $hrefs[0];
    }

    /**
     * @return list<string>
     */
    private function nodes(TestResponse $response, string $xpath): array
    {
        $found = (new DOMXPath($this->document($response)))->query($xpath);
        $values = [];
        foreach ($found as $node) {
            $values[] = $node->nodeValue;
        }

        return $values;
    }

    private function countNodes(TestResponse $response, string $xpath): int
    {
        return (new DOMXPath($this->document($response)))->query($xpath)->length;
    }

    private function document(TestResponse $response): DOMDocument
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();

        return $document;
    }

    private function queryCount(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }
}
