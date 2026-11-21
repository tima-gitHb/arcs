<?php

namespace Tests\Feature\Manager;

use App\Enums\Role;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\User;
use App\Services\CountryList;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * M13 — Airports page.
 *
 * Routes these tests expect (added in step 3):
 *   GET  /manager/airports                 manager.airports          list, add form, edit forms
 *   POST /manager/airports                 manager.airports.store    code, name, city, country, timezone, min_connection_minutes
 *   PUT  /manager/airports/{airport}       manager.airports.update   the same fields except code (the code in the URL stays)
 * There is no delete route.
 *
 * code is uppercased before the 3-letter rule. country is the ISO-2 code from <x-country-select>.
 * timezone is one of DateTimeZone::listIdentifiers(), checked with Laravel's timezone rule.
 * min_connection_minutes is an integer from 0 to 1440.
 *
 * Audit: one row per changed column, passenger_id and flight_id null.
 *   field "airport CDG minutes|timezone|name|city|country"
 *   country old/new are country names, not ISO codes.
 *   a new airport writes exactly one row, field "airport MXP added", old null, new the code.
 * The audit page labels a field that starts with "airport " as Airports (data-audit-subject="airports").
 */
class AirportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 10:00:00');
        $this->manager = $this->actingAsRole(Role::Manager);
    }

    /**
     * A valid new airport. Each test overrides only what it checks.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newAirport(array $overrides = []): array
    {
        return $overrides + [
            'code' => 'MXP',
            'name' => 'Milan Malpensa',
            'city' => 'Milan',
            'country' => 'IT',
            'timezone' => 'Europe/Rome',
            'min_connection_minutes' => 45,
        ];
    }

    /**
     * CDG as it was inserted by the airports migration.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function cdg(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Paris Charles de Gaulle',
            'city' => 'Paris',
            'country' => 'FR',
            'timezone' => 'Europe/Paris',
            'min_connection_minutes' => 60,
        ];
    }

    public function test_the_airports_page_lists_the_network_and_the_add_and_edit_forms(): void
    {
        // A zone no airport uses yet: the select is the full IANA list, not the zones already stored.
        $extraZone = collect(DateTimeZone::listIdentifiers())
            ->first(fn (string $zone) => Airport::query()->where('timezone', $zone)->doesntExist());

        $this->get('/manager/airports')
            ->assertOk()
            ->assertSee('BEY')
            ->assertSee('Beirut')
            ->assertSee('CDG')
            ->assertSee('Paris')
            ->assertDontSee('name="code"', false)
            ->assertDontSee('Delete');

        $this->get('/manager/airports/create')
            ->assertOk()
            ->assertSee('name="code"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="city"', false)
            ->assertSee('name="country"', false)
            ->assertSee('Search country')
            ->assertSee('name="timezone"', false)
            ->assertSee('value="'.$extraZone.'"', false)
            ->assertSee('name="min_connection_minutes"', false);

        $this->get('/manager/airports/CDG/edit')
            ->assertOk()
            ->assertSee('/manager/airports/CDG', false)
            ->assertSee('name="name"', false)
            ->assertDontSee('name="code"', false);
    }

    public function test_the_new_airport_form_has_no_time_zone_preselected(): void
    {
        $html = $this->get('/manager/airports/create')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<select id="timezone" name="timezone".*?<\/select>/s', $html, $match));

        $this->assertStringContainsString('<option value="" disabled selected>Choose a time zone</option>', $match[0]);
        $this->assertDoesNotMatchRegularExpression('/<option value="[^"]+"[^>]*\bselected\b/', $match[0]);
        $this->assertMatchesRegularExpression('/id="min_connection_minutes"[^>]*value="60"/', $html);
    }

    public function test_the_airport_routes_are_get_post_and_put_with_no_delete(): void
    {
        $this->assertTrue(Route::has('manager.airports'));
        $this->assertTrue(Route::has('manager.airports.store'));
        $this->assertTrue(Route::has('manager.airports.update'));
        $this->assertFalse(Route::has('manager.airports.destroy'));
        $this->assertContains('PUT', Route::getRoutes()->getByName('manager.airports.update')->methods());
    }

    public function test_the_manager_menu_links_to_the_airports_page(): void
    {
        $this->assertSame('manager.airports', Role::Manager->menu()['Airports'] ?? null);

        $this->get('/manager')->assertOk()->assertSee('Airports');

        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->followingRedirects()->get(route($role->homeRoute()))->assertOk()->assertDontSee('Airports');
        }
    }

    public function test_adding_an_airport_uppercases_the_code_and_writes_one_audit_row(): void
    {
        $this->post('/manager/airports', $this->newAirport(['code' => 'mxp']))
            ->assertRedirect('/manager/airports')
            ->assertSessionHasNoErrors();

        $airport = Airport::find('MXP');
        $this->assertNotNull($airport);
        $this->assertSame('Milan Malpensa', $airport->name);
        $this->assertSame('Milan', $airport->city);
        $this->assertSame('IT', $airport->country);
        $this->assertSame('Europe/Rome', $airport->timezone);
        $this->assertSame(45, $airport->min_connection_minutes);
        // The stored code is uppercase. MySQL compares the primary key without case, so find('mxp') is this same row.
        $this->assertSame('MXP', $airport->code);
        $this->assertSame(20, Airport::count());

        $entry = AuditEntry::sole();
        $this->assertSame('airport MXP added', $entry->field);
        $this->assertNull($entry->old_value);
        $this->assertSame('MXP', $entry->new_value);
        $this->assertNull($entry->passenger_id);
        $this->assertNull($entry->flight_id);
        $this->assertSame($this->manager->id, $entry->user_id);
    }

    public function test_a_code_that_is_not_three_letters_is_refused(): void
    {
        foreach (['BE', 'BEYY', 'B2Y', 'cd', ''] as $code) {
            $this->from('/manager/airports')
                ->post('/manager/airports', $this->newAirport(['code' => $code]))
                ->assertRedirect('/manager/airports')
                ->assertSessionHasErrors('code');
        }

        $this->assertSame(19, Airport::count());
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $this->from('/manager/airports')
            ->post('/manager/airports', $this->newAirport(['code' => 'bey']))
            ->assertRedirect('/manager/airports')
            ->assertSessionHasErrors('code');

        $bey = Airport::find('BEY');
        $this->assertSame(19, Airport::count());
        $this->assertSame('Beirut', $bey->city);
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_an_invalid_time_zone_is_refused_and_writes_nothing(): void
    {
        foreach (['Not/AZone', 'Paris', ''] as $zone) {
            $this->from('/manager/airports')
                ->post('/manager/airports', $this->newAirport(['timezone' => $zone]))
                ->assertRedirect('/manager/airports')
                ->assertSessionHasErrors('timezone');
        }

        // A bad zone on an edit does not save the valid neighbour.
        $this->from('/manager/airports')
            ->put('/manager/airports/CDG', $this->cdg([
                'timezone' => 'Not/AZone',
                'min_connection_minutes' => 90,
            ]))
            ->assertRedirect('/manager/airports')
            ->assertSessionHasErrors('timezone');

        $cdg = Airport::find('CDG');
        $this->assertSame('Europe/Paris', $cdg->timezone);
        $this->assertSame(60, $cdg->min_connection_minutes);
        $this->assertNull(Airport::find('MXP'));
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_connection_minutes_outside_0_to_1440_are_refused(): void
    {
        foreach ([-1, 1441, '90.5', 'abc', ''] as $minutes) {
            $this->from('/manager/airports')
                ->post('/manager/airports', $this->newAirport(['min_connection_minutes' => $minutes]))
                ->assertRedirect('/manager/airports')
                ->assertSessionHasErrors('min_connection_minutes');
        }

        $this->from('/manager/airports')
            ->put('/manager/airports/CDG', $this->cdg([
                'name' => 'Changed name',
                'min_connection_minutes' => 1441,
            ]))
            ->assertRedirect('/manager/airports')
            ->assertSessionHasErrors('min_connection_minutes');

        $this->assertSame('Paris Charles de Gaulle', Airport::find('CDG')->name);
        $this->assertSame(60, Airport::find('CDG')->min_connection_minutes);
        $this->assertSame(19, Airport::count());
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_connection_minutes_of_0_and_1440_are_saved(): void
    {
        $this->post('/manager/airports', $this->newAirport(['min_connection_minutes' => 0]))
            ->assertRedirect('/manager/airports')
            ->assertSessionHasNoErrors();
        $this->assertSame(0, Airport::find('MXP')->min_connection_minutes);

        $this->post('/manager/airports', $this->newAirport([
            'code' => 'BGY', 'name' => 'Milan Bergamo', 'min_connection_minutes' => 1440,
        ]))->assertRedirect('/manager/airports')->assertSessionHasNoErrors();
        $this->assertSame(1440, Airport::find('BGY')->min_connection_minutes);
    }

    public function test_an_unknown_country_is_refused(): void
    {
        $this->from('/manager/airports')
            ->post('/manager/airports', $this->newAirport(['country' => 'ZZ']))
            ->assertRedirect('/manager/airports')
            ->assertSessionHasErrors('country');

        $this->assertNull(Airport::find('MXP'));
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_editing_an_airport_keeps_its_code_and_audits_each_changed_column(): void
    {
        $countries = app(CountryList::class);

        $this->put('/manager/airports/CDG', [
            'code' => 'XYZ',
            'name' => 'Charles de Gaulle',
            'city' => 'Roissy',
            'country' => 'IT',
            'timezone' => 'Pacific/Auckland',
            'min_connection_minutes' => 90,
        ])->assertRedirect('/manager/airports')->assertSessionHasNoErrors();

        $this->assertNull(Airport::find('XYZ'));
        $cdg = Airport::find('CDG');
        $this->assertNotNull($cdg);
        $this->assertSame('Charles de Gaulle', $cdg->name);
        $this->assertSame('Roissy', $cdg->city);
        $this->assertSame('IT', $cdg->country);
        $this->assertSame('Pacific/Auckland', $cdg->timezone);
        $this->assertSame(90, $cdg->min_connection_minutes);

        $entries = AuditEntry::all();
        $this->assertSame(
            ['airport CDG city', 'airport CDG country', 'airport CDG minutes', 'airport CDG name', 'airport CDG timezone'],
            $entries->pluck('field')->sort()->values()->all(),
        );
        $this->assertTrue($entries->every(fn (AuditEntry $entry) => $entry->passenger_id === null && $entry->flight_id === null && $entry->user_id === $this->manager->id));

        $byField = $entries->keyBy('field');
        $this->assertSame('Paris Charles de Gaulle', $byField['airport CDG name']->old_value);
        $this->assertSame('Charles de Gaulle', $byField['airport CDG name']->new_value);
        $this->assertSame('Paris', $byField['airport CDG city']->old_value);
        $this->assertSame('Roissy', $byField['airport CDG city']->new_value);
        $this->assertSame($countries->name('FR'), $byField['airport CDG country']->old_value);
        $this->assertSame($countries->name('IT'), $byField['airport CDG country']->new_value);
        $this->assertSame('Europe/Paris', $byField['airport CDG timezone']->old_value);
        $this->assertSame('Pacific/Auckland', $byField['airport CDG timezone']->new_value);
        $this->assertSame('60', $byField['airport CDG minutes']->old_value);
        $this->assertSame('90', $byField['airport CDG minutes']->new_value);
    }

    public function test_changing_only_the_minimum_connection_time_writes_one_audit_row(): void
    {
        $this->put('/manager/airports/CDG', $this->cdg(['min_connection_minutes' => 75]))
            ->assertRedirect('/manager/airports')
            ->assertSessionHasNoErrors();

        $this->assertSame(75, Airport::find('CDG')->min_connection_minutes);

        $entry = AuditEntry::sole();
        $this->assertSame('airport CDG minutes', $entry->field);
        $this->assertSame('60', $entry->old_value);
        $this->assertSame('75', $entry->new_value);
        $this->assertNull($entry->passenger_id);
        $this->assertNull($entry->flight_id);
    }

    public function test_saving_an_airport_unchanged_writes_nothing(): void
    {
        $this->put('/manager/airports/CDG', $this->cdg())
            ->assertRedirect('/manager/airports')
            ->assertSessionHasNoErrors();

        $this->assertSame(60, Airport::find('CDG')->min_connection_minutes);
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_the_audit_page_labels_an_airport_row_airports(): void
    {
        AuditEntry::create([
            'field' => 'airport CDG minutes',
            'old_value' => '60',
            'new_value' => '90',
            'user_id' => $this->manager->id,
            'changed_at' => now(),
        ]);

        $this->get(route('manager.audit'))
            ->assertOk()
            ->assertSee('data-audit-subject="airports"', false)
            ->assertSee('Airports')
            ->assertSee('airport CDG minutes')
            ->assertSee('60 → 90', false)
            ->assertDontSee('Flight —');
    }

    public function test_a_non_manager_cannot_open_or_change_airports(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->get('/manager/airports')->assertForbidden();
            $this->post('/manager/airports', $this->newAirport())->assertForbidden();
            $this->put('/manager/airports/CDG', $this->cdg(['min_connection_minutes' => 90]))->assertForbidden();
        }

        $this->assertNull(Airport::find('MXP'));
        $this->assertSame(60, Airport::find('CDG')->min_connection_minutes);
        $this->assertSame(0, AuditEntry::count());
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get('/manager/airports')->assertRedirect(route('login'));
        $this->post('/manager/airports', $this->newAirport())->assertRedirect(route('login'));
        $this->put('/manager/airports/CDG', $this->cdg())->assertRedirect(route('login'));
        $this->assertSame(19, Airport::count());
    }
}
