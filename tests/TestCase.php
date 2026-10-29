<?php

namespace Tests;

use App\Enums\FlightStatus;
use App\Enums\Role;
use App\Models\Flight;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a user with this role and log in as them.
     */
    protected function actingAsRole(Role|string $role): User
    {
        $user = User::factory()->create([
            'role' => $role instanceof Role ? $role : Role::from($role),
        ]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * Create a flight in the given status, reached through StatusService (never forceFill).
     * The check-in window is open around now(): opens 1h ago, closes in 1h15, departure in 2h.
     * Call $this->travelTo() first if the test needs fixed times.
     *
     * @param  array<string, mixed>  $attributes  overrides for the factory
     */
    protected function flightWithStatus(FlightStatus $status, array $attributes = []): Flight
    {
        $flight = Flight::factory()->create($attributes + [
            'departure_at' => now()->addHours(2),
            'checkin_opens_at' => now()->subHour(),
            'checkin_closes_at' => now()->addMinutes(75),
        ]);

        $service = app(StatusService::class);
        $manager = User::factory()->create(['role' => Role::Manager]);

        $path = match ($status) {
            FlightStatus::Scheduled => [],
            FlightStatus::CheckinOpen => [FlightStatus::CheckinOpen],
            FlightStatus::Boarding, FlightStatus::Closed, FlightStatus::Departed => [FlightStatus::CheckinOpen, FlightStatus::Boarding],
            FlightStatus::Delayed => [FlightStatus::Delayed],
            FlightStatus::Cancelled => [FlightStatus::Cancelled],
        };

        foreach ($path as $step) {
            $service->setFlightStatus($flight, $step, $manager);
        }

        // Boarding -> Closed only exists through closeBoarding().
        if ($status === FlightStatus::Closed || $status === FlightStatus::Departed) {
            $service->closeBoarding($flight, $manager);
        }
        if ($status === FlightStatus::Departed) {
            $service->setFlightStatus($flight, FlightStatus::Departed, $manager);
        }

        return $flight;
    }
}
