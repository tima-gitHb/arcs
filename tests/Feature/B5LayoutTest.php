<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class B5LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_role_sees_its_own_badge_and_only_its_own_menu(): void
    {
        foreach (Role::cases() as $role) {
            $user = User::factory()->create(['role' => $role, 'name' => 'Agent '.$role->value]);

            $response = $this->actingAs($user)->followingRedirects()->get(route($role->homeRoute()));

            $response->assertOk()
                ->assertSee($role->label())
                ->assertSee('Agent '.$role->value)
                ->assertSee('Profile')
                ->assertSee('Log out');

            foreach (Role::cases() as $other) {
                foreach (array_keys($other->menu()) as $label) {
                    $other === $role
                        ? $response->assertSee($label)
                        : $response->assertDontSee($label);
                }
            }
        }
    }

    public function test_checkin_does_not_see_staff_accounts(): void
    {
        $user = User::factory()->create(['role' => Role::Checkin]);

        $this->actingAs($user)->get('/checkin')
            ->assertSee('Check in passenger')
            ->assertDontSee('Staff accounts');
    }

    public function test_current_page_is_highlighted(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);

        // "Flight lists" is manager.home: a link marked as the current page.
        $this->actingAs($user)->get('/manager')
            ->assertSee('href="'.route('manager.home').'"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_flash_messages_are_displayed(): void
    {
        $user = User::factory()->create(['role' => Role::Gate]);

        $this->actingAs($user)
            ->withSession(['success' => 'Passenger boarded.', 'error' => 'Seat taken.'])
            ->get('/gate')
            ->assertSee('Passenger boarded.')
            ->assertSee('Seat taken.');
    }

    public function test_login_page_shows_arcs_and_no_laravel_logo(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('ARCS')
            ->assertSee('Airline Reservation &amp; Check-in System', false)
            ->assertDontSee('viewBox="0 0 316 316"', false);
    }
}
