<?php

namespace Tests\Feature\Manager;

use App\Enums\CabinClass;
use App\Enums\Role;
use App\Http\Requests\Manager\StoreAircraftRequest;
use App\Models\Aircraft;
use App\Models\BaggageAllowance;
use App\Models\Passenger;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\Setting;
use App\Services\AllowanceService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * M8 — Premium class in the aircraft form and baggage allowance per class (SR-54).
 *
 * Routes (all exist already): POST manager.aircraft.store, GET manager.flights,
 * GET manager.rules, PUT manager.rules.update.
 * New form field: premium_rows (after business_rows). The hard max comes from the
 * settings row hard_max_kg_per_bag (seeded as 32; 32 is the fallback when it is missing).
 */
class PremiumAllowanceTest extends TestCase
{
    use RefreshDatabase;

    private const TOO_MANY_ROWS = 'Business and Premium rows cannot be more than the total rows.';

    protected function setUp(): void
    {
        parent::setUp();

        // passport_min_months = 6, hard_max_kg_per_bag = 32, Economy 2 x 23, Premium 2 x 23, Business 2 x 32
        $this->seed(SettingSeeder::class);
        $this->actingAsRole(Role::Manager);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validAircraft(array $overrides = []): array
    {
        return $overrides + [
            'registration' => 'OD-PRM',
            'model' => 'A320',
            'rows' => 30,
            'seats_per_row' => 6,
            'business_rows' => 5,
            'premium_rows' => 3,
        ];
    }

    /**
     * A complete, valid rules form. Each test overrides only the part it checks.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rulesPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'passport_min_months' => 6,
            'allowances' => [
                'economy' => ['pieces' => 2, 'max_kg_per_bag' => 23],
                'premium' => ['pieces' => 2, 'max_kg_per_bag' => 23],
                'business' => ['pieces' => 2, 'max_kg_per_bag' => 32],
            ],
        ], $overrides);
    }

    // ── Aircraft form: Premium rows ──────────────────────────────────────────

    public function test_SR54_premium_rows_follow_business_rows_and_make_premium_seats(): void
    {
        $this->post(route('manager.aircraft.store'), $this->validAircraft())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('manager.aircraft.index'));

        $aircraft = Aircraft::where('registration', 'OD-PRM')->sole();
        $this->assertSame(3, $aircraft->premium_rows);
        $this->assertSame(180, $aircraft->seats()->count());
        $this->assertSame(30, $aircraft->seats()->where('cabin_class', CabinClass::Business)->count());
        $this->assertSame(18, $aircraft->seats()->where('cabin_class', CabinClass::Premium)->count());
        $this->assertSame(132, $aircraft->seats()->where('cabin_class', CabinClass::Economy)->count());

        // Rows 1-5 Business, 6-8 Premium, 9-30 Economy: check both edges of the Premium block.
        $this->assertSame(CabinClass::Business, $aircraft->seats()->where('seat_no', '5F')->sole()->cabin_class);
        $this->assertSame(CabinClass::Premium, $aircraft->seats()->where('seat_no', '6A')->sole()->cabin_class);
        $this->assertSame(CabinClass::Premium, $aircraft->seats()->where('seat_no', '8F')->sole()->cabin_class);
        $this->assertSame(CabinClass::Economy, $aircraft->seats()->where('seat_no', '9A')->sole()->cabin_class);
    }

    public function test_aircraft_form_has_a_premium_rows_field_after_business_rows(): void
    {
        $this->get(route('manager.aircraft.create'))
            ->assertOk()
            ->assertSee('name="premium_rows"', false)
            ->assertSeeInOrder(['name="business_rows"', 'name="premium_rows"'], false);
    }

    public function test_the_success_message_shows_the_seat_count_per_class(): void
    {
        $this->post(route('manager.aircraft.store'), $this->validAircraft());

        $message = session('success');
        $this->assertStringContainsString('OD-PRM', $message);
        $this->assertStringContainsString('Business 30', $message);
        $this->assertStringContainsString('Premium 18', $message);
        $this->assertStringContainsString('Economy 132', $message);
    }

    public function test_without_premium_rows_the_aircraft_has_no_premium_seats(): void
    {
        $this->post(route('manager.aircraft.store'), $this->validAircraft(['premium_rows' => null]))
            ->assertSessionHasNoErrors();

        $aircraft = Aircraft::where('registration', 'OD-PRM')->sole();
        $this->assertSame(0, $aircraft->premium_rows);
        $this->assertSame(0, $aircraft->seats()->where('cabin_class', CabinClass::Premium)->count());
        $this->assertSame(150, $aircraft->seats()->where('cabin_class', CabinClass::Economy)->count());
    }

    public function test_business_plus_premium_equal_to_total_rows_is_accepted(): void
    {
        $this->post(route('manager.aircraft.store'), $this->validAircraft(['rows' => 10, 'business_rows' => 4, 'premium_rows' => 6]))
            ->assertSessionHasNoErrors();

        $aircraft = Aircraft::where('registration', 'OD-PRM')->sole();
        $this->assertSame(0, $aircraft->seats()->where('cabin_class', CabinClass::Economy)->count());
    }

    public function test_business_plus_premium_more_than_total_rows_is_rejected_with_the_service_message(): void
    {
        // 5 + 6 = 11 > 10, although each alone is within the rows.
        $this->post(route('manager.aircraft.store'), $this->validAircraft(['rows' => 10, 'business_rows' => 5, 'premium_rows' => 6]))
            ->assertSessionHasErrors(['premium_rows' => self::TOO_MANY_ROWS]);

        $this->assertSame(0, Aircraft::count());
        $this->assertDatabaseCount('seats', 0);
    }

    public function test_the_form_request_itself_checks_business_plus_premium(): void
    {
        // The rule lives in StoreAircraftRequest (the service keeps the same check as a safety net).
        $data = $this->validAircraft(['rows' => 10, 'business_rows' => 5, 'premium_rows' => 6]);
        $request = StoreAircraftRequest::create('/manager/aircraft', 'POST', $data); // rules() reads rows and business_rows from it

        $validator = Validator::make($data, $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertSame([self::TOO_MANY_ROWS], $validator->errors()->get('premium_rows'));
    }

    public function test_negative_premium_rows_are_rejected(): void
    {
        $this->post(route('manager.aircraft.store'), $this->validAircraft(['premium_rows' => -1]))
            ->assertSessionHasErrors('premium_rows');

        $this->assertSame(0, Aircraft::count());
    }

    // ── Rules page: allowance per class and the hard max ─────────────────────

    public function test_the_rules_page_shows_all_three_classes_and_the_limit(): void
    {
        $this->get(route('manager.rules'))
            ->assertOk()
            ->assertSeeInOrder(['Economy', 'Premium', 'Business']);

        $this->get(route('manager.rules.edit', 'baggage'))
            ->assertOk()
            ->assertSee('name="allowances[premium][pieces]"', false)
            ->assertSee('name="allowances[premium][max_kg_per_bag]"', false)
            ->assertSee('max 32 kg per bag');
    }

    public function test_the_kg_inputs_use_the_hard_max_as_their_max_attribute(): void
    {
        $html = $this->get(route('manager.rules.edit', 'baggage'))->getContent();

        // One kg input per class, each capped at 32 (not the old 999.9).
        $this->assertSame(3, preg_match_all('/name="allowances\[\w+\]\[max_kg_per_bag\]"[^>]*max="32"/', $html));
        $this->assertStringNotContainsString('max="999.9"', $html);
    }

    public function test_the_limit_shown_follows_the_setting(): void
    {
        Setting::where('key', 'hard_max_kg_per_bag')->update(['value' => '30']);

        $this->get(route('manager.rules.edit', 'baggage'))
            ->assertSee('max 30 kg per bag')
            ->assertSee('max="30"', false);
    }

    public function test_SR54_manager_can_change_the_premium_allowance(): void
    {
        $this->put(route('manager.rules.update'), $this->rulesPayload([
            'allowances' => ['premium' => ['pieces' => 3, 'max_kg_per_bag' => 28.5]],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('manager.rules'));

        $premium = BaggageAllowance::where('cabin_class', CabinClass::Premium)->sole();
        $this->assertSame(3, $premium->pieces);
        $this->assertSame('28.5', $premium->max_kg_per_bag);

        // Economy and Business are untouched.
        $this->assertSame('23.0', BaggageAllowance::where('cabin_class', CabinClass::Economy)->sole()->max_kg_per_bag);
        $this->assertSame('32.0', BaggageAllowance::where('cabin_class', CabinClass::Business)->sole()->max_kg_per_bag);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cabinKeys(): array
    {
        return ['economy' => ['economy'], 'premium' => ['premium'], 'business' => ['business']];
    }

    #[DataProvider('cabinKeys')]
    public function test_exactly_the_hard_max_is_accepted(string $cabin): void
    {
        $this->put(route('manager.rules.update'), $this->rulesPayload([
            'allowances' => [$cabin => ['pieces' => 2, 'max_kg_per_bag' => 32]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('32.0', BaggageAllowance::where('cabin_class', CabinClass::from(ucfirst($cabin)))->sole()->max_kg_per_bag);
    }

    #[DataProvider('cabinKeys')]
    public function test_kg_per_bag_above_the_hard_max_is_rejected_and_nothing_is_saved(string $cabin): void
    {
        $this->put(route('manager.rules.update'), $this->rulesPayload([
            'passport_min_months' => 3,
            'allowances' => [$cabin => ['pieces' => 5, 'max_kg_per_bag' => 32.1]],
        ]))->assertSessionHasErrors("allowances.$cabin.max_kg_per_bag");

        // Nothing at all was saved, not even the valid passport months.
        $this->assertSame('6', Setting::find('passport_min_months')->value);
        $this->assertSame(2, BaggageAllowance::where('cabin_class', CabinClass::from(ucfirst($cabin)))->sole()->pieces);
    }

    public function test_the_cap_follows_the_hard_max_setting(): void
    {
        Setting::where('key', 'hard_max_kg_per_bag')->update(['value' => '30']);

        $this->put(route('manager.rules.update'), $this->rulesPayload([
            'allowances' => ['economy' => ['pieces' => 2, 'max_kg_per_bag' => 31]],
        ]))->assertSessionHasErrors('allowances.economy.max_kg_per_bag');

        $this->put(route('manager.rules.update'), $this->rulesPayload([
            'allowances' => ['economy' => ['pieces' => 2, 'max_kg_per_bag' => 30], 'business' => ['pieces' => 2, 'max_kg_per_bag' => 30]],
        ]))->assertSessionHasNoErrors();
    }

    public function test_the_cap_falls_back_to_32_when_the_setting_is_missing(): void
    {
        Setting::where('key', 'hard_max_kg_per_bag')->delete();

        $this->put(route('manager.rules.update'), $this->rulesPayload([
            'allowances' => ['business' => ['pieces' => 2, 'max_kg_per_bag' => 32.1]],
        ]))->assertSessionHasErrors('allowances.business.max_kg_per_bag');

        $this->put(route('manager.rules.update'), $this->rulesPayload())->assertSessionHasNoErrors();

        $this->get(route('manager.rules.edit', 'baggage'))->assertSee('max 32 kg per bag');
    }

    // ── The edited allowance reaches AllowanceService::for() ────────────────

    public function test_changing_the_premium_allowance_changes_what_allowance_service_returns_for_a_premium_seat(): void
    {
        $passenger = Passenger::factory()->create(['cabin_class' => CabinClass::Economy]);
        $flight = $passenger->reservation->flight;
        $seat = Seat::factory()->create(['aircraft_id' => $flight->aircraft_id, 'cabin_class' => CabinClass::Premium]);
        SeatAssignment::create(['flight_id' => $flight->id, 'seat_id' => $seat->id, 'passenger_id' => $passenger->id]);

        $this->put(route('manager.rules.update'), $this->rulesPayload([
            'allowances' => ['premium' => ['pieces' => 4, 'max_kg_per_bag' => 27]],
        ]))->assertSessionHasNoErrors();

        $allowance = app(AllowanceService::class)->for($passenger->fresh());

        // The seat decides the class: Premium, although the booked class is Economy.
        $this->assertSame(CabinClass::Premium, $allowance->cabinClass);
        $this->assertSame(4, $allowance->pieces);
        $this->assertSame(27.0, $allowance->kgPerBag);
        $this->assertSame(108.0, $allowance->totalKg); // 4 x 27
    }

    public function test_changing_the_premium_allowance_changes_what_allowance_service_returns_for_a_premium_booking(): void
    {
        $premium = Passenger::factory()->create(['cabin_class' => CabinClass::Premium]); // no seat yet
        $economy = Passenger::factory()->create(['cabin_class' => CabinClass::Economy]);

        $this->put(route('manager.rules.update'), $this->rulesPayload([
            'allowances' => ['premium' => ['pieces' => 4, 'max_kg_per_bag' => 27]],
        ]))->assertSessionHasNoErrors();

        $service = app(AllowanceService::class);

        $this->assertSame(4, $service->for($premium)->pieces);
        $this->assertSame(27.0, $service->for($premium)->kgPerBag);

        // The Economy passenger keeps the Economy allowance.
        $this->assertSame(2, $service->for($economy)->pieces);
        $this->assertSame(23.0, $service->for($economy)->kgPerBag);
    }

    public function test_non_managers_cannot_post_aircraft_with_premium_rows_or_change_the_rules(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->post(route('manager.aircraft.store'), $this->validAircraft())->assertForbidden();
            $this->put(route('manager.rules.update'), $this->rulesPayload())->assertForbidden();
        }

        $this->assertSame(0, Aircraft::count());
    }
}
