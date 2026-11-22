<?php

namespace Tests\Feature\Manager;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Fare;
use App\Models\Flight;
use App\Services\FareService;
use App\Support\Money;
use Database\Seeders\FareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * M14 — Fares per route (SR-69). The M7 page gains a route selector. Defaults stay as they are.
 *
 * GET manager.fares with no route shows the default fares, exactly as M7.
 * GET/PUT field "route": "BEY-CDG", or empty for the defaults.
 * The selector's first option is <option value="">Default prices</option>, then one option per
 * directed route that has a flight or already has a fare (no duplicates), value "BEY-CDG",
 * label "BEY → CDG (Beirut → Paris)".
 * On a route, an empty fare cell is allowed: no row, so the quote uses the default.
 * The input is empty and its placeholder is the default in dollars ("250.00").
 * A typed price creates the route fare. Clearing an existing one deletes it.
 * A default fare is never created or deleted here. Fees stay the one list, all required.
 * A bad amount uses the M7 rules and the error key fares.economy.adult (an empty cell is not bad).
 * Audit field "fare BEY-CDG Economy Adult" (the "fare " prefix, at most 30 characters).
 * old_value and new_value are Money::format, or the word "default" when there was or will be no row.
 */
class FareRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 10:00:00');
        $this->seed(FareSeeder::class);
        $this->actingAsRole(Role::Manager);
    }

    private function flight(string $number, string $origin, string $destination): Flight
    {
        return Flight::factory()->create([
            'flight_no' => $number,
            'origin' => $origin,
            'destination' => $destination,
        ]);
    }

    /**
     * Every fare cell is sent. An empty string on a route means "no own price".
     *
     * @param  array<string, array<string, string>>  $fares
     * @return array<string, mixed>
     */
    private function routePayload(string $route, array $fares = []): array
    {
        $blank = [
            'economy' => ['adult' => '', 'child' => '', 'infant' => ''],
            'premium' => ['adult' => '', 'child' => '', 'infant' => ''],
            'business' => ['adult' => '', 'child' => '', 'infant' => ''],
        ];

        return [
            'route' => $route,
            'fares' => array_replace_recursive($blank, $fares),
            'fees' => [
                'ExtraBag' => '50.00',
                'ExcessKg' => '10.00',
                'SportsEquipment' => '75.00',
                'Pet' => '60.00',
            ],
        ];
    }

    /**
     * @param  array<string, array<string, string>>  $fares
     */
    private function save(string $route, array $fares): TestResponse
    {
        return $this->put(route('manager.fares.update'), $this->routePayload($route, $fares));
    }

    private function quote(CabinClass $cabin, PassengerType $type, ?string $origin = null, ?string $destination = null): int
    {
        return app(FareService::class)->quote(
            [['cabin_class' => $cabin, 'type' => $type]],
            origin: $origin,
            destination: $destination,
        )->totalCents;
    }

    public function test_SR69_each_route_has_its_own_fare_and_the_default_is_unchanged(): void
    {
        $this->flight($this->flightNo('201'), 'BEY', 'CDG');
        $this->flight($this->flightNo('264'), 'BEY', 'IST');
        $this->flight($this->flightNo('110'), 'BEY', 'LHR');

        $this->save('BEY-CDG', ['economy' => ['adult' => '300.00']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('manager.fares', ['route' => 'BEY-CDG']));
        $this->save('BEY-IST', ['economy' => ['adult' => '180.00']])
            ->assertSessionHasNoErrors();

        $adult = PassengerType::Adult;
        $economy = CabinClass::Economy;

        // The route fare, per route. A route with no row, another class on a priced route,
        // the opposite direction, and a quote with no route at all: the default.
        $this->assertSame(30000, $this->quote($economy, $adult, 'BEY', 'CDG'));
        $this->assertSame(18000, $this->quote($economy, $adult, 'BEY', 'IST'));
        $this->assertSame(25000, $this->quote($economy, $adult, 'BEY', 'LHR'));
        $this->assertSame(18750, $this->quote($economy, PassengerType::Child, 'BEY', 'CDG'));
        $this->assertSame(25000, $this->quote($economy, $adult, 'IST', 'BEY'));
        $this->assertSame(25000, $this->quote($economy, $adult));

        $cdg = AuditEntry::where('field', 'fare BEY-CDG Economy Adult')->sole();
        $this->assertSame('default', $cdg->old_value);
        $this->assertSame(Money::format(30000), $cdg->new_value);
        $this->assertNull($cdg->passenger_id);
        $this->assertNull($cdg->flight_id);

        $ist = AuditEntry::where('field', 'fare BEY-IST Economy Adult')->sole();
        $this->assertSame('default', $ist->old_value);
        $this->assertSame(Money::format(18000), $ist->new_value);
    }

    public function test_the_selector_lists_default_first_then_flown_and_priced_routes(): void
    {
        $this->flight($this->flightNo('201'), 'BEY', 'CDG');
        $this->flight($this->flightNo('202'), 'BEY', 'CDG');
        $this->flight($this->flightNo('305'), 'BEY', 'DXB');
        Fare::create([
            'origin_airport_code' => 'CDG',
            'destination_airport_code' => 'BEY',
            'cabin_class' => CabinClass::Economy,
            'passenger_type' => PassengerType::Adult,
            'amount_cents' => 18000,
            'currency' => 'USD',
        ]);

        $html = $this->get(route('manager.fares'))->assertOk()->assertSee('value="250.00"', false)->getContent();

        $this->assertMatchesRegularExpression(
            '/<select\b[^>]*\bname="route"[^>]*>\s*<option value=""[^>]*>Default prices<\/option>/s',
            $html,
        );
        $this->assertSame(1, substr_count($html, 'value="BEY-CDG"'));
        $html = $this->selectHtml($html);
        $this->assertSeeInOrderOn($html, [
            'Default prices',
            'BEY → CDG (Beirut → Paris)',
            'BEY → DXB (Beirut → Dubai)',
            'CDG → BEY (Paris → Beirut)',
        ]);
        $this->assertSame(4, preg_match_all('/<option\b/', $html));
        $this->assertStringNotContainsString('value="BEY-LHR"', $html);
    }

    public function test_a_route_without_its_own_fare_shows_an_empty_input_and_the_default_as_placeholder(): void
    {
        $this->flight($this->flightNo('201'), 'BEY', 'CDG');

        $page = $this->get(route('manager.fares', ['route' => 'BEY-CDG']))->assertOk();

        $page->assertSee('placeholder="250.00"', false)
            ->assertSee('placeholder="187.50"', false)
            ->assertDontSee('value="250.00"', false)
            ->assertSee('value="50.00"', false);
    }

    public function test_typing_a_price_creates_only_that_route_fare(): void
    {
        $this->flight($this->flightNo('201'), 'BEY', 'CDG');

        $this->save('BEY-CDG', ['economy' => ['adult' => '300.00']])->assertSessionHasNoErrors();

        $fare = Fare::query()->forRoute('BEY', 'CDG')->sole();
        $this->assertSame(CabinClass::Economy, $fare->cabin_class);
        $this->assertSame(PassengerType::Adult, $fare->passenger_type);
        $this->assertSame(30000, $fare->amount_cents);
        $this->assertSame('USD', $fare->currency);
        $this->assertSame(9, Fare::query()->defaults()->count());
        $this->assertSame(25000, $this->quote(CabinClass::Economy, PassengerType::Adult));

        $this->get(route('manager.fares', ['route' => 'BEY-CDG']))
            ->assertOk()
            ->assertSee('value="300.00"', false);
    }

    public function test_changing_a_route_price_audits_the_old_and_new_amounts(): void
    {
        $this->flight($this->flightNo('201'), 'BEY', 'CDG');
        $this->save('BEY-CDG', ['economy' => ['adult' => '300.00']])->assertSessionHasNoErrors();

        $this->save('BEY-CDG', ['economy' => ['adult' => '320.00']])->assertSessionHasNoErrors();

        $this->assertSame(32000, Fare::query()->forRoute('BEY', 'CDG')->sole()->amount_cents);
        $this->assertSame(32000, $this->quote(CabinClass::Economy, PassengerType::Adult, 'BEY', 'CDG'));

        $entry = AuditEntry::where('field', 'fare BEY-CDG Economy Adult')->orderByDesc('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame(Money::format(30000), $entry->old_value);
        $this->assertSame(Money::format(32000), $entry->new_value);
        $this->assertSame(2, AuditEntry::where('field', 'fare BEY-CDG Economy Adult')->count());
    }

    public function test_clearing_a_route_price_deletes_it_and_audits_default(): void
    {
        $this->flight($this->flightNo('201'), 'BEY', 'CDG');
        $this->save('BEY-CDG', ['economy' => ['adult' => '300.00']])->assertSessionHasNoErrors();

        $this->save('BEY-CDG', ['economy' => ['adult' => '']])->assertSessionHasNoErrors();

        $this->assertSame(0, Fare::query()->forRoute('BEY', 'CDG')->count());
        $this->assertSame(9, Fare::query()->defaults()->count());
        $this->assertSame(25000, $this->quote(CabinClass::Economy, PassengerType::Adult, 'BEY', 'CDG'));
        $this->assertSame(25000, $this->quote(CabinClass::Economy, PassengerType::Adult));

        $entry = AuditEntry::where('field', 'fare BEY-CDG Economy Adult')->orderByDesc('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame(Money::format(30000), $entry->old_value);
        $this->assertSame('default', $entry->new_value);
    }

    /**
     * The same rejected text as M7, except an empty cell: on a route that means "no own price".
     *
     * @return array<string, array{string}>
     */
    public static function badAmounts(): array
    {
        return [
            'negative' => ['-1'],
            'not a number' => ['abc'],
            'more than 2 decimals' => ['10.505'],
            'comma' => ['1,50'],
            'above 99999.99' => ['100000'],
            'currency symbol' => ['$250'],
        ];
    }

    #[DataProvider('badAmounts')]
    public function test_a_bad_route_amount_is_rejected_and_nothing_is_saved(string $bad): void
    {
        $this->flight($this->flightNo('201'), 'BEY', 'CDG');

        $this->save('BEY-CDG', [
            'economy' => ['adult' => $bad, 'child' => '10.00'],
        ])->assertSessionHasErrors('fares.economy.adult')
            ->assertSessionDoesntHaveErrors('fares.premium.adult');

        $this->assertSame(0, Fare::query()->whereNotNull('origin_airport_code')->count());
        $this->assertSame(25000, $this->quote(CabinClass::Economy, PassengerType::Adult, 'BEY', 'CDG'));
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_a_bad_fee_on_the_route_form_is_rejected_and_the_empty_fare_is_not(): void
    {
        $this->flight($this->flightNo('201'), 'BEY', 'CDG');

        $payload = $this->routePayload('BEY-CDG', ['economy' => ['adult' => '300.00']]);
        $payload['fees']['ExtraBag'] = 'abc';

        $this->put(route('manager.fares.update'), $payload)
            ->assertSessionHasErrors('fees.ExtraBag')
            ->assertSessionDoesntHaveErrors(['fares.economy.adult', 'fares.economy.child']);

        $this->assertSame(0, Fare::query()->forRoute('BEY', 'CDG')->count());
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_an_invalid_route_is_refused_and_writes_nothing(): void
    {
        $this->flight($this->flightNo('201'), 'BEY', 'CDG');

        foreach (['XXX-YYY', 'BEY-BEY', 'BEY', 'BEYCDG'] as $route) {
            $this->save($route, ['economy' => ['adult' => '300.00']])
                ->assertSessionHasErrors('route');
        }

        $this->assertSame(0, Fare::query()->whereNotNull('origin_airport_code')->count());
        $this->assertSame(9, Fare::query()->defaults()->count());
        $this->assertSame(25000, $this->quote(CabinClass::Economy, PassengerType::Adult));
        $this->assertSame(0, AuditEntry::count());

        // Lower case is the same route once it is uppercased.
        $this->save('bey-cdg', ['economy' => ['adult' => '300.00']])->assertSessionHasNoErrors();
        $this->assertSame(30000, Fare::query()->forRoute('BEY', 'CDG')->sole()->amount_cents);
    }

    public function test_a_non_manager_cannot_open_or_save_a_route(): void
    {
        $this->flight($this->flightNo('201'), 'BEY', 'CDG');

        foreach ([Role::Reservation, Role::Checkin, Role::Gate] as $role) {
            $this->actingAsRole($role);

            $this->get(route('manager.fares', ['route' => 'BEY-CDG']))->assertForbidden();
            $this->save('BEY-CDG', ['economy' => ['adult' => '300.00']])->assertForbidden();
        }

        $this->assertSame(0, Fare::query()->forRoute('BEY', 'CDG')->count());
        $this->assertSame(0, AuditEntry::count());
    }

    private function selectHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<select\b[^>]*\bname="route"[^>]*>.*?<\/select>/s', $html, $match));

        return $match[0];
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertSeeInOrderOn(string $html, array $needles): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $at = strpos($html, $needle, $offset);
            $this->assertNotFalse($at, "Missing [{$needle}] after position {$offset}.");
            $offset = $at + strlen($needle);
        }
    }
}
