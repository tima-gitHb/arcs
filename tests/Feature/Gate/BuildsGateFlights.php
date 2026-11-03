<?php

namespace Tests\Feature\Gate;

use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;

/**
 * Shared setup for the gate tests (G1–G3). Every status is reached through StatusService.
 */
trait BuildsGateFlights
{
    protected User $checkinAgent;

    protected User $gateAgent;

    protected function makeAgents(): void
    {
        $this->checkinAgent = User::factory()->create(['role' => Role::Checkin, 'name' => 'Maya Khoury']);
        $this->gateAgent = $this->actingAsRole('gate');
        $this->gateAgent->update(['name' => 'Joe Saliba']);
    }

    /**
     * A Booked passenger with a valid passport and (unless an infant) a seat.
     */
    protected function passengerOn(Flight $flight, ?Reservation $reservation = null, array $attributes = []): Passenger
    {
        $passenger = Passenger::factory()
            ->for($reservation ?? Reservation::factory()->for($flight))
            ->create($attributes);
        TravelDocument::factory()->for($passenger)->create();

        if ($passenger->type !== PassengerType::Infant) {
            $this->seatPassenger($passenger);
        }

        return $passenger;
    }

    protected function checkIn(Passenger ...$passengers): void
    {
        foreach ($passengers as $passenger) {
            app(StatusService::class)->checkIn($passenger, $this->checkinAgent);
        }
    }

    protected function startBoarding(Flight $flight): void
    {
        app(StatusService::class)->setFlightStatus($flight, FlightStatus::Boarding, $this->gateAgent);
    }

    protected function board(Passenger ...$passengers): void
    {
        foreach ($passengers as $passenger) {
            app(StatusService::class)->board($passenger, $this->gateAgent);
        }
    }
}
