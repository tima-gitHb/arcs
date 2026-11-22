<?php

namespace Tests\Feature\Manager;

use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Fare;
use App\Models\Fee;
use App\Models\Flight;
use App\Models\User;
use App\Services\FareService;
use Database\Seeders\FareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * M7 — Fares and fees (SR-56).
 *
 * Routes these tests expect (added in step 3):
 *   GET  manager.fares          the page
 *   PUT  manager.fares.update   fields, all amounts in dollars:
 *                               fares[<cabin>][<type>]   cabin = economy|premium|business,
 *                                                        type = adult|child|infant   (9 values)
 *                               fees[<FeeCode value>]    ExtraBag|ExcessKg|SportsEquipment|Pet   (4 values)
 * Menu item: 'Fares and fees' => 'manager.fares'.
 * Audit: field "fare Business Adult" / "fee ExtraBag", old and new as formatted dollars ("$800.00"),
 * no passenger and no flight. Errors are under keys like fares.business.adult and fees.ExtraBag.
 */
class FareTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 10:00:00');

        // 9 fares and 4 fees, in cents: see FareSeeder (Business Adult 80000 = $800.00, Extra bag 5000 = $50.00).
        $this->seed(FareSeeder::class);
        $this->manager = $this->actingAsRole(Role::Manager);
    }

    /**
     * The form exactly as the seeded prices show on the page. Each test overrides only what it checks.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'fares' => [
                'economy' => ['adult' => '250.00', 'child' => '187.50', 'infant' => '25.00'],
                'premium' => ['adult' => '400.00', 'child' => '300.00', 'infant' => '40.00'],
                'business' => ['adult' => '800.00', 'child' => '600.00', 'infant' => '80.00'],
            ],
            'fees' => [
                'ExtraBag' => '50.00',
                'ExcessKg' => '10.00',
                'SportsEquipment' => '75.00',
                'Pet' => '60.00',
            ],
        ], $overrides);
    }

    private function fareCents(CabinClass $cabin, PassengerType $type): int
    {
        return Fare::where('cabin_class', $cabin)->where('passenger_type', $type)->sole()->amount_cents;
    }

    private function feeCents(FeeCode $code): int
    {
        return Fee::where('code', $code)->sole()->amount_cents;
    }

    /**
     * @return array<string, int>
     */
    private function allCents(): array
    {
        return Fare::all()->mapWithKeys(fn (Fare $f) => ["fare {$f->cabin_class->value} {$f->passenger_type->value}" => $f->amount_cents])->all()
            + Fee::all()->mapWithKeys(fn (Fee $f) => ["fee {$f->code->value}" => $f->amount_cents])->all();
    }

    // ── The page ─────────────────────────────────────────────────────────────

    public function test_SR56_the_page_shows_every_fare_and_fee_in_dollars(): void
    {
        $this->get(route('manager.fares'))
            ->assertOk()
            ->assertSee('Fares and fees')
            // 3 classes x 3 passenger types, each an input named fares[cabin][type] with the price in dollars.
            ->assertSee('name="fares[economy][adult]"', false)
            ->assertSee('name="fares[premium][child]"', false)
            ->assertSee('name="fares[business][infant]"', false)
            ->assertSee('value="250.00"', false)
            ->assertSee('value="187.50"', false)
            ->assertSee('value="800.00"', false)
            // 4 fees, labelled as in the FeeCode enum.
            ->assertSee('name="fees[ExtraBag]"', false)
            ->assertSee('name="fees[ExcessKg]"', false)
            ->assertSee('name="fees[SportsEquipment]"', false)
            ->assertSee('name="fees[Pet]"', false)
            ->assertSee('Extra bag')
            ->assertSee('Excess baggage (per kg)')
            ->assertSee('Sports equipment')
            ->assertSee('Pet')
            ->assertSee('value="75.00"', false);
    }

    public function test_the_page_has_one_input_per_price_and_no_more(): void
    {
        $html = $this->get(route('manager.fares'))->getContent();

        $this->assertSame(9, preg_match_all('/name="fares\[\w+\]\[\w+\]"/', $html));
        $this->assertSame(4, preg_match_all('/name="fees\[\w+\]"/', $html));
    }

    public function test_the_manager_menu_has_the_fares_item(): void
    {
        $this->assertSame('manager.fares', Role::Manager->menu()['Fares and fees'] ?? null);
        $this->assertTrue(Route::has('manager.fares'));

        $this->get(route('manager.home'))->assertOk()->assertSee('Fares and fees');
    }

    public function test_other_roles_do_not_see_the_fares_item(): void
    {
        foreach ([Role::Reservation, Role::Checkin, Role::Gate] as $role) {
            $this->actingAsRole($role);

            $this->followingRedirects()->get(route($role->homeRoute()))
                ->assertOk()
                ->assertDontSee('Fares and fees');
        }
    }

    // ── Saving (dollars in, integer cents stored) ───────────────────────────

    public function test_SR56_manager_can_change_a_fare(): void
    {
        $this->put(route('manager.fares.update'), $this->payload([
            'fares' => ['business' => ['adult' => '760.00']],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('manager.fares'));

        $this->assertSame(76000, $this->fareCents(CabinClass::Business, PassengerType::Adult));
        $this->assertSame('USD', Fare::where('cabin_class', CabinClass::Business)->where('passenger_type', PassengerType::Adult)->sole()->currency);
    }

    public function test_SR56_manager_can_change_a_fee(): void
    {
        $this->put(route('manager.fares.update'), $this->payload(['fees' => ['ExtraBag' => '55.50']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(5550, $this->feeCents(FeeCode::ExtraBag));
    }

    public function test_only_the_changed_price_changes(): void
    {
        $before = $this->allCents();

        $this->put(route('manager.fares.update'), $this->payload(['fares' => ['premium' => ['child' => '310.00']]]));

        $expected = $before;
        $expected['fare Premium Child'] = 31000;
        $this->assertSame($expected, $this->allCents());
    }

    public function test_dollars_with_cents_are_stored_as_exact_cents(): void
    {
        // 19.99 x 100 is 1998.9999... as a float: the stored value must be exactly 1999.
        $this->put(route('manager.fares.update'), $this->payload([
            'fares' => ['economy' => ['adult' => '19.99', 'child' => '0.29', 'infant' => '1.15']],
            'fees' => ['Pet' => '7.5'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1999, $this->fareCents(CabinClass::Economy, PassengerType::Adult));
        $this->assertSame(29, $this->fareCents(CabinClass::Economy, PassengerType::Child));
        $this->assertSame(115, $this->fareCents(CabinClass::Economy, PassengerType::Infant));
        $this->assertSame(750, $this->feeCents(FeeCode::Pet));
    }

    public function test_whole_dollars_without_decimals_are_accepted(): void
    {
        $this->put(route('manager.fares.update'), $this->payload(['fares' => ['business' => ['adult' => '800']]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(80000, $this->fareCents(CabinClass::Business, PassengerType::Adult));
    }

    public function test_zero_is_a_valid_price(): void
    {
        $this->put(route('manager.fares.update'), $this->payload([
            'fares' => ['economy' => ['infant' => '0']],
            'fees' => ['Pet' => '0.00'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, $this->fareCents(CabinClass::Economy, PassengerType::Infant));
        $this->assertSame(0, $this->feeCents(FeeCode::Pet));
    }

    public function test_the_highest_allowed_price_is_99999_99(): void
    {
        $this->put(route('manager.fares.update'), $this->payload([
            'fares' => ['business' => ['adult' => '99999.99']],
            'fees' => ['ExcessKg' => '99999.99'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(9999999, $this->fareCents(CabinClass::Business, PassengerType::Adult));
        $this->assertSame(9999999, $this->feeCents(FeeCode::ExcessKg));
    }

    public function test_the_page_never_creates_or_deletes_a_row(): void
    {
        $fareIds = Fare::orderBy('id')->pluck('id')->all();
        $feeIds = Fee::orderBy('id')->pluck('id')->all();

        $this->put(route('manager.fares.update'), $this->payload([
            'fares' => ['economy' => ['adult' => '260.00'], 'business' => ['infant' => '80.00']],
            'fees' => ['SportsEquipment' => '80.00'],
        ]))->assertSessionHasNoErrors();

        // Still exactly 9 fares and 4 fees, and they are the same rows (same ids), only with new amounts.
        $this->assertSame(9, Fare::count());
        $this->assertSame(4, Fee::count());
        $this->assertSame($fareIds, Fare::orderBy('id')->pluck('id')->all());
        $this->assertSame($feeIds, Fee::orderBy('id')->pluck('id')->all());
    }

    public function test_extra_unknown_prices_in_the_form_create_nothing(): void
    {
        $this->put(route('manager.fares.update'), $this->payload([
            'fares' => ['first' => ['adult' => '999.00'], 'economy' => ['senior' => '10.00']],
            'fees' => ['Parachute' => '5.00'],
        ]));

        $this->assertSame(9, Fare::count());
        $this->assertSame(4, Fee::count());
        $this->assertSame(0, Fee::where('code', 'Parachute')->count());
    }

    // ── Validation ──────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function badAmounts(): array
    {
        return [
            'negative' => ['-1'],
            'negative cents' => ['-0.50'],
            'not a number' => ['abc'],
            'empty' => [''],
            'more than 2 decimals' => ['10.505'],
            'comma' => ['1,50'],
            'above 99999.99' => ['100000'],
            'just above 99999.99' => ['99999.995'],
            'way above' => ['99999999999'],
            'currency symbol' => ['$250'],
            'exponent' => ['1e3'],
        ];
    }

    #[DataProvider('badAmounts')]
    public function test_a_bad_fare_amount_is_rejected_and_nothing_is_saved(string $bad): void
    {
        $before = $this->allCents();

        $this->put(route('manager.fares.update'), $this->payload([
            // One good change next to the bad one: it must not be saved either.
            'fees' => ['Pet' => '65.00'],
            'fares' => ['business' => ['adult' => $bad]],
        ]))->assertSessionHasErrors('fares.business.adult');

        $this->assertSame($before, $this->allCents());
        $this->assertSame(0, AuditEntry::count());
    }

    #[DataProvider('badAmounts')]
    public function test_a_bad_fee_amount_is_rejected_and_nothing_is_saved(string $bad): void
    {
        $before = $this->allCents();

        $this->put(route('manager.fares.update'), $this->payload([
            'fares' => ['economy' => ['adult' => '260.00']],
            'fees' => ['ExtraBag' => $bad],
        ]))->assertSessionHasErrors('fees.ExtraBag');

        $this->assertSame($before, $this->allCents());
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_a_missing_price_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['fares']['premium']['infant'], $payload['fees']['Pet']);

        $this->put(route('manager.fares.update'), $payload)
            ->assertSessionHasErrors(['fares.premium.infant', 'fees.Pet']);

        $this->assertSame(0, AuditEntry::count());
    }

    public function test_a_failed_save_keeps_what_the_manager_typed(): void
    {
        $this->from(route('manager.fares'))
            ->put(route('manager.fares.update'), $this->payload(['fares' => ['business' => ['adult' => 'abc']]]))
            ->assertRedirect(route('manager.fares'));

        $this->get(route('manager.fares'))->assertSee('value="abc"', false);
    }

    // ── Audit (old -> new, who, when) ───────────────────────────────────────

    public function test_SR56_a_changed_fare_writes_one_audit_row(): void
    {
        $this->put(route('manager.fares.update'), $this->payload(['fares' => ['business' => ['adult' => '760.00']]]));

        $entry = AuditEntry::sole();
        $this->assertSame('fare Business Adult', $entry->field);
        $this->assertSame('$800.00', $entry->old_value);
        $this->assertSame('$760.00', $entry->new_value);
        $this->assertSame($this->manager->id, $entry->user_id);
        $this->assertEquals(now(), $entry->changed_at);
        // A price belongs to no passenger and no flight.
        $this->assertNull($entry->passenger_id);
        $this->assertNull($entry->flight_id);
    }

    public function test_SR56_a_changed_fee_writes_one_audit_row(): void
    {
        $this->put(route('manager.fares.update'), $this->payload(['fees' => ['ExtraBag' => '55.50']]));

        $entry = AuditEntry::sole();
        $this->assertSame('fee ExtraBag', $entry->field);
        $this->assertSame('$50.00', $entry->old_value);
        $this->assertSame('$55.50', $entry->new_value);
        $this->assertSame($this->manager->id, $entry->user_id);
        $this->assertNull($entry->passenger_id);
        $this->assertNull($entry->flight_id);
    }

    public function test_every_changed_value_gets_its_own_row_and_unchanged_values_get_none(): void
    {
        $this->put(route('manager.fares.update'), $this->payload([
            'fares' => ['economy' => ['adult' => '260.00'], 'premium' => ['infant' => '45.00']],
            'fees' => ['Pet' => '65.00'],
        ]));

        $this->assertSame(
            ['fare Economy Adult', 'fare Premium Infant', 'fee Pet'],
            AuditEntry::orderBy('field')->pluck('field')->all(),
        );
    }

    public function test_saving_without_changes_writes_no_audit_row(): void
    {
        $before = $this->allCents();

        $this->put(route('manager.fares.update'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(0, AuditEntry::count());
        $this->assertSame($before, $this->allCents());
    }

    public function test_the_same_amount_typed_differently_is_not_a_change(): void
    {
        // "250" and "250.0" are the same 25000 cents as the seeded "250.00".
        $this->put(route('manager.fares.update'), $this->payload([
            'fares' => ['economy' => ['adult' => '250', 'child' => '187.5']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, AuditEntry::count());
    }

    public function test_the_audit_page_labels_a_price_change_fares_and_fees(): void
    {
        $this->put(route('manager.fares.update'), $this->payload(['fares' => ['business' => ['adult' => '760.00']]]));

        $this->get(route('manager.audit'))
            ->assertOk()
            ->assertSee('data-audit-subject="fares"', false)
            ->assertSee('fare Business Adult')
            ->assertSee('$800.00 → $760.00', false)
            ->assertSee($this->manager->name)
            ->assertDontSee('Flight —');
    }

    public function test_a_row_is_labelled_by_its_field_not_by_what_is_missing(): void
    {
        // Neither passenger nor flight, but not a price: it must not be called "Fares and fees".
        AuditEntry::create([
            'field' => 'status', 'old_value' => 'Booked', 'new_value' => 'CheckedIn',
            'user_id' => $this->manager->id, 'changed_at' => now(),
        ]);

        $this->get(route('manager.audit'))
            ->assertOk()
            ->assertDontSee('data-audit-subject="fares"', false)
            ->assertSee('data-audit-subject="flight"', false)
            ->assertSee('Flight —');
    }

    public function test_the_audit_page_still_labels_a_flight_row_with_its_flight(): void
    {
        $flight = Flight::factory()->create(['flight_no' => $this->flightNo('777')]);
        AuditEntry::forFlight($flight, 'status', 'Scheduled', 'CheckinOpen', $this->manager);

        $this->get(route('manager.audit'))
            ->assertOk()
            ->assertSee('data-audit-subject="flight"', false)
            ->assertSee('Flight '.$this->flightNo('777'))
            ->assertDontSee('data-audit-subject="fares"', false);
    }

    // ── FareService reads the new prices ────────────────────────────────────

    public function test_SR56_fare_service_quotes_the_new_prices(): void
    {
        $this->put(route('manager.fares.update'), $this->payload([
            'fares' => ['business' => ['adult' => '760.00']],
            'fees' => ['ExtraBag' => '55.50'],
        ]));

        $quote = app(FareService::class)->quote(
            [['cabin_class' => CabinClass::Business, 'type' => PassengerType::Adult]],
            ['extra_bags' => 2],
        );

        $this->assertSame(76000 + 2 * 5550, $quote->totalCents);
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function test_non_managers_cannot_open_or_change_the_prices(): void
    {
        $before = $this->allCents();

        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->get(route('manager.fares'))->assertForbidden();
            $this->put(route('manager.fares.update'), $this->payload(['fares' => ['business' => ['adult' => '1.00']]]))->assertForbidden();
        }

        $this->assertSame($before, $this->allCents());
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get(route('manager.fares'))->assertRedirect(route('login'));
        $this->put(route('manager.fares.update'), $this->payload())->assertRedirect(route('login'));
    }
}
