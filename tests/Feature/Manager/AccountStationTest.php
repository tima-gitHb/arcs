<?php

namespace Tests\Feature\Manager;

use App\Enums\Role;
use App\Models\Flight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * M13 — station on staff accounts.
 *
 * The create form keeps airport_code optional (AccountTest posts without one).
 * It shows the hint "Required for check-in and gate staff".
 * The list shows "CDG · Paris", or "—" when airport_code is null.
 *
 * Each row saves with:
 *   PATCH /manager/accounts/{user}/station    manager.accounts.station
 *   field airport_code, nullable, an airports.code.
 */
class AccountStationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newAccount(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Claire Petit',
            'email' => 'claire@arcs.test',
            'role' => 'checkin',
            'airport_code' => 'CDG',
        ];
    }

    public function test_the_station_route_is_a_patch(): void
    {
        $this->assertTrue(Route::has('manager.accounts.station'));
        $this->assertContains('PATCH', Route::getRoutes()->getByName('manager.accounts.station')->methods());
    }

    public function test_the_accounts_list_shows_each_persons_station(): void
    {
        $paris = User::factory()->create([
            'name' => 'Claire Petit', 'email' => 'claire@arcs.test', 'role' => Role::Checkin, 'airport_code' => 'CDG',
        ]);
        User::factory()->create([
            'name' => 'Nina None', 'email' => 'nina@arcs.test', 'role' => Role::Checkin, 'airport_code' => null,
        ]);
        $this->actingAsRole(Role::Manager);

        $this->get('/manager/accounts')
            ->assertOk()
            ->assertSee('CDG · Paris')
            ->assertSee('Nina None')
            ->assertSee('—');

        $this->get('/manager/accounts/'.$paris->id)
            ->assertOk()
            ->assertSee('BEY · Beirut')
            ->assertSee('name="airport_code"', false)
            ->assertSee('/manager/accounts/'.$paris->id.'/station', false)
            ->assertSee('Save');
    }

    public function test_the_create_form_says_a_station_is_required_for_check_in_and_gate(): void
    {
        $this->actingAsRole(Role::Manager);

        $this->get('/manager/accounts/create')
            ->assertOk()
            ->assertSee('Required for check-in and gate staff');
    }

    public function test_a_new_check_in_account_with_station_cdg_sees_only_cdg_departures(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-15 08:00', 'UTC'));
        Flight::factory()->create(['flight_no' => 'ME202', 'origin' => 'CDG', 'destination' => 'BEY', 'departure_at' => '2026-11-15 10:00']);
        Flight::factory()->create(['flight_no' => 'ME201', 'origin' => 'BEY', 'destination' => 'CDG', 'departure_at' => '2026-11-15 12:00']);
        Flight::factory()->create(['flight_no' => 'ME216', 'origin' => 'IST', 'destination' => 'CDG', 'departure_at' => '2026-11-15 11:00']);
        $this->actingAsRole(Role::Manager);

        $this->post('/manager/accounts', $this->newAccount())->assertRedirect();

        $claire = User::where('email', 'claire@arcs.test')->firstOrFail();
        $this->assertSame(Role::Checkin, $claire->role);
        $this->assertSame('CDG', $claire->airport_code);

        $this->actingAs($claire);
        $this->get('/checkin')
            ->assertOk()
            ->assertSee('ME202')
            ->assertDontSee('ME201')
            ->assertDontSee('ME216');
    }

    public function test_a_station_can_be_left_empty_on_create(): void
    {
        Notification::fake();
        $this->actingAsRole(Role::Manager);

        $this->post('/manager/accounts', $this->newAccount(['airport_code' => '']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'claire@arcs.test')->firstOrFail()->airport_code);
    }

    public function test_saving_a_station_on_a_row_sets_it(): void
    {
        $claire = User::factory()->create([
            'name' => 'Claire Petit', 'role' => Role::Checkin, 'airport_code' => null,
        ]);
        $this->actingAsRole(Role::Manager);

        $this->patch("/manager/accounts/{$claire->id}/station", ['airport_code' => 'CDG'])
            ->assertRedirect('/manager/accounts/'.$claire->id)
            ->assertSessionHasNoErrors();

        $this->assertSame('CDG', $claire->fresh()->airport_code);
    }

    public function test_clearing_a_station_on_a_row_removes_it(): void
    {
        $claire = User::factory()->create([
            'name' => 'Claire Petit', 'role' => Role::Checkin, 'airport_code' => 'CDG',
        ]);
        $this->actingAsRole(Role::Manager);

        $this->patch("/manager/accounts/{$claire->id}/station", ['airport_code' => ''])
            ->assertRedirect('/manager/accounts/'.$claire->id)
            ->assertSessionHasNoErrors();

        $this->assertNull($claire->fresh()->airport_code);
    }

    public function test_an_unknown_station_is_refused_and_the_account_is_unchanged(): void
    {
        $claire = User::factory()->create([
            'name' => 'Claire Petit', 'role' => Role::Checkin, 'airport_code' => 'BEY',
        ]);
        $this->actingAsRole(Role::Manager);

        $this->from('/manager/accounts/'.$claire->id)
            ->patch("/manager/accounts/{$claire->id}/station", ['airport_code' => 'ZZZ'])
            ->assertRedirect('/manager/accounts/'.$claire->id)
            ->assertSessionHasErrors('airport_code');

        $this->assertSame('BEY', $claire->fresh()->airport_code);
    }

    public function test_a_non_manager_cannot_set_a_station(): void
    {
        $claire = User::factory()->create([
            'name' => 'Claire Petit', 'role' => Role::Gate, 'airport_code' => null,
        ]);

        foreach (Role::cases() as $role) {
            if ($role === Role::Manager) {
                continue;
            }

            $this->actingAsRole($role);
            $this->patch("/manager/accounts/{$claire->id}/station", ['airport_code' => 'CDG'])->assertForbidden();
        }

        $this->assertNull($claire->fresh()->airport_code);
    }
}
