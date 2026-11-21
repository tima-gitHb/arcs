<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI2 — reservation create uses the left shell (today's flights in the menu),
 * not a main-area table; confirmation shows the PNR.
 */
class UI2ReservationShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_UI2_reservation_create_hides_todays_flights_table_from_the_main_area(): void
    {
        $this->actingAsRole('reservation');

        $html = $this->get(route('reservation.create'))
            ->assertOk()
            ->assertSee("Today's flights", false)
            ->assertSee('data-role="reservation"', false)
            ->assertSee('data-booking-flow', false)
            ->assertSee('Profile')
            ->assertSee('Log out')
            ->getContent();

        $this->assertStringNotContainsString('>Today\'s flights</h2>', $html);
        $this->assertStringContainsString('data-user-card', $html);
    }

    public function test_UI2_checkin_and_gate_menus_show_todays_flights_and_user_actions(): void
    {
        foreach ([Role::Checkin, Role::Gate] as $role) {
            $user = User::factory()->create(['role' => $role, 'airport_code' => 'BEY']);

            $this->actingAs($user)
                ->followingRedirects()
                ->get(route($role->homeRoute()))
                ->assertOk()
                ->assertSee("Today's flights", false)
                ->assertSee('data-role="'.$role->value.'"', false)
                ->assertSee('Profile')
                ->assertSee('Log out')
                ->assertSee('data-user-card', false);
        }
    }

    public function test_UI2_confirmation_page_shows_the_pnr(): void
    {
        $this->actingAsRole('reservation');
        $this->seedBookingPrices();

        $reservation = Reservation::factory()->create(['pnr' => 'UI2PNR']);

        $this->get(route('reservation.confirmation', $reservation))
            ->assertOk()
            ->assertSee('UI2PNR')
            ->assertSee('Booking confirmed');
    }
}
