<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class B6RoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every role page: [route name, owning role, page title, task ID].
     * A null task ID means the page is built, so it no longer shows "Coming soon".
     *
     * @return array<string, array{string, Role, string, string|null}>
     */
    public static function pages(): array
    {
        return [
            'reservation.create' => ['reservation.create', Role::Reservation, 'New reservation', null],
            'reservation.search' => ['reservation.search', Role::Reservation, 'Find reservation', null],
            'checkin.home' => ['checkin.home', Role::Checkin, 'Check in passenger', 'C1'],
            'gate.home' => ['gate.home', Role::Gate, 'Manifest', 'G1'],
            'manager.home' => ['manager.home', Role::Manager, 'Flight lists', 'M3'],
            'manager.flights' => ['manager.flights', Role::Manager, 'Flights and aircraft', 'M1'],
            'manager.audit' => ['manager.audit', Role::Manager, 'Audit log', 'M5'],
        ];
    }

    #[DataProvider('pages')]
    public function test_route_returns_200_for_its_own_role(string $route, Role $role, string $title, ?string $task): void
    {
        $this->actingAsRole($role);

        $response = $this->get(route($route))
            ->assertOk()
            ->assertSee($title);

        if ($task !== null) {
            $response->assertSee('Coming soon — task '.$task);
        }
    }

    #[DataProvider('pages')]
    public function test_route_returns_403_for_the_other_roles(string $route, Role $role): void
    {
        foreach (Role::cases() as $other) {
            if ($other === $role) {
                continue;
            }

            $this->actingAsRole($other);
            $this->get(route($route))->assertForbidden();
        }
    }

    #[DataProvider('pages')]
    public function test_guest_is_redirected_to_login(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public function test_reservation_home_redirects_to_new_reservation(): void
    {
        $this->actingAsRole('reservation');

        $this->get(route('reservation.home'))
            ->assertRedirect(route('reservation.create'));
    }

    public function test_no_menu_item_is_greyed_out(): void
    {
        foreach (Role::cases() as $role) {
            foreach ($role->menu() as $routeName) {
                $this->assertTrue(Route::has($routeName), "Missing route {$routeName}");
            }

            $this->actingAsRole($role);

            $this->followingRedirects()->get(route($role->homeRoute()))
                ->assertOk()
                ->assertDontSee('aria-disabled="true"', false);
        }
    }
}
