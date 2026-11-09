<?php

namespace Tests;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\Role;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\StatusService;
use Database\Seeders\FareSeeder;
use Database\Seeders\SettingSeeder;
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
     * Give the passenger a seat on their flight's aircraft, as check-in needs one
     * before StatusService::checkIn() (C5). The seat is created if needed.
     */
    protected function seatPassenger(Passenger $passenger, ?string $seatNo = null): Seat
    {
        $flight = $passenger->reservation->flight;

        if ($seatNo === null) {
            $row = 1;
            while (Seat::where('aircraft_id', $flight->aircraft_id)->where('seat_no', $row.'F')->exists()) {
                $row++;
            }
            $seatNo = $row.'F';
        }

        $seat = Seat::firstOrCreate(
            ['aircraft_id' => $flight->aircraft_id, 'seat_no' => $seatNo],
            ['cabin_class' => CabinClass::Economy],
        );

        SeatAssignment::updateOrCreate(
            ['passenger_id' => $passenger->id],
            ['flight_id' => $flight->id, 'seat_id' => $seat->id],
        );

        $passenger->unsetRelation('seatAssignment');

        return $seat;
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

    /**
     * Fares, fees and baggage allowances needed for a paid booking.
     */
    protected function seedBookingPrices(): void
    {
        $this->seed(FareSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    /**
     * Card + optional contact fields for reservation.store (test gateway).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function paidBookingFields(array $overrides = []): array
    {
        return array_merge([
            'contact_email' => null,
            'contact_phone' => null,
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/30',
            'card_cvc' => '123',
        ], $overrides);
    }
}
