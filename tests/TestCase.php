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
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Airline IATA designator from config (default AR).
     */
    protected function airlineCode(): string
    {
        return (string) config('arcs.airline.code');
    }

    /**
     * Build a flight number with the configured airline code (e.g. flightNo('205') → AR205).
     */
    protected function flightNo(string|int $number): string
    {
        return $this->airlineCode().$number;
    }

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
            'booking_token' => (string) Str::uuid(),
        ], $overrides);
    }

    /**
     * Mark travel documents verified so gate boarding (#48) can succeed in tests.
     */
    protected function verifyPassengerDocuments(Passenger $passenger, ?User $by = null): void
    {
        $by ??= User::factory()->create(['role' => Role::Checkin]);

        foreach ($passenger->travelDocuments()->get() as $document) {
            if ($document->verified_at !== null) {
                continue;
            }

            $document->forceFill([
                'verified_at' => now(),
                'verified_by' => $by->id,
            ])->save();
        }
    }

    /**
     * @return list<int>
     */
    protected function seatsFor(Flight $flight, int $count, string $cabin = 'Economy'): array
    {
        $now = now();
        $rows = [];
        $letter = match ($cabin) {
            'Business' => 'A',
            'Premium' => 'C',
            default => 'A',
        };
        $start = Seat::query()->where('aircraft_id', $flight->aircraft_id)->count() + 1;

        for ($number = 0; $number < $count; $number++) {
            $rows[] = [
                'aircraft_id' => $flight->aircraft_id,
                'seat_no' => ($start + $number).$letter,
                'cabin_class' => $cabin,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Seat::query()->insert($rows);

        return Seat::query()
            ->where('aircraft_id', $flight->aircraft_id)
            ->whereIn('seat_no', array_column($rows, 'seat_no'))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
