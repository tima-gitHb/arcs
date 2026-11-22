<?php

namespace Tests\Feature\Validation;

use App\Enums\Role;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every named web route: owning role is not 403; every other role is 403 (#34).
 */
class RoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_route_is_allowed_or_403(): void
    {
        $this->seedBookingPrices();

        $fixtures = $this->fixtures();

        $checked = 0;

        foreach ($this->roleRoutes() as [$route, $owner]) {
            $methods = array_values(array_filter(
                $route->methods(),
                fn (string $method) => ! in_array($method, ['HEAD', 'OPTIONS'], true),
            ));
            $method = $methods[0] ?? 'GET';
            $uri = $this->uriFor($route, $fixtures);
            if ($uri === null) {
                continue;
            }

            foreach (Role::cases() as $role) {
                $this->actingAsRole($role);
                $response = $this->call($method, $uri);

                $status = $response->getStatusCode();

                if ($role === $owner) {
                    $this->assertNotSame(
                        403,
                        $status,
                        "{$role->value} should access {$method} {$route->getName()} (got {$status})",
                    );
                } else {
                    // Role middleware forbids with 403; unresolved bindings may 404 — both deny access.
                    $this->assertContains(
                        $status,
                        [403, 404],
                        "{$role->value} must be denied on {$method} {$route->getName()} (owner {$owner->value}, got {$status})",
                    );
                }
                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked, 'Matrix should cover many role×route pairs');
    }

    /**
     * @return list<array{0: RoutingRoute, 1: Role}>
     */
    private function roleRoutes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! is_string($name) || $name === '') {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $owner = null;
            foreach ($middleware as $item) {
                if (is_string($item) && str_starts_with($item, 'role:')) {
                    $owner = Role::from(substr($item, 5));
                    break;
                }
            }

            if ($owner === null) {
                continue;
            }

            $out[] = [$route, $owner];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $fixtures
     */
    private function uriFor(RoutingRoute $route, array $fixtures): ?string
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            if (! array_key_exists($name, $fixtures)) {
                return null;
            }
            $parameters[$name] = $fixtures[$name];
        }

        try {
            return url(route($route->getName(), $parameters, false));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtures(): array
    {
        $flight = Flight::factory()->create();
        $reservation = Reservation::factory()->for($flight)->create();
        $passenger = Passenger::factory()->for($reservation)->create();
        $this->seatPassenger($passenger);
        $document = TravelDocument::factory()->for($passenger)->create();
        $bag = Bag::factory()->for($passenger)->create();
        $user = User::factory()->create(['role' => Role::Gate]);
        $aircraft = Aircraft::factory()->create();
        $airport = Airport::query()->first() ?? Airport::factory()->create();

        return [
            'flight' => $flight,
            'reservation' => $reservation,
            'passenger' => $passenger,
            'document' => $document,
            'bag' => $bag,
            'user' => $user,
            'account' => $user,
            'aircraft' => $aircraft,
            'airport' => $airport,
            'fare' => 1,
            'fee' => 1,
            'tripFare' => 1,
            'payment' => 1,
        ];
    }
}
