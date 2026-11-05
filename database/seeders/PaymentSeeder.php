<?php

namespace Database\Seeders;

use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\User;
use App\Services\FareService;
use App\Services\Payments\CardDetails;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;

/**
 * One paid reservation on ME 201 so the reports have data, through PaymentService with the
 * fake gateway's test card.
 */
class PaymentSeeder extends Seeder
{
    public function run(FareService $fares, PaymentService $payments): void
    {
        $reservation = Reservation::query()
            ->whereHas('flight', fn ($flight) => $flight->where('flight_no', 'ME201'))
            ->orderBy('id')
            ->firstOrFail();

        $quote = $fares->quote($reservation->passengers->map(fn (Passenger $passenger) => [
            'cabin_class' => $passenger->cabin_class,
            'type' => $passenger->type,
        ])->all());

        $payments->charge(
            new CardDetails('4242 4242 4242 4242', now()->addYear()->format('m/y'), '123'),
            $quote->totalCents,
            PaymentKind::Fare,
            User::where('role', Role::Reservation)->firstOrFail(),
            reservation: $reservation,
        );
    }
}
