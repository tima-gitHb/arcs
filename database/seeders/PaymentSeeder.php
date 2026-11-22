<?php

namespace Database\Seeders;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Bag;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AllowanceService;
use App\Services\FareService;
use App\Services\Payments\CardDetails;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;

/**
 * Demo payments: a failed fare attempt then a success on the Paris flight, plus excess kg on a heavy bag.
 */
class PaymentSeeder extends Seeder
{
    public function run(FareService $fares, PaymentService $payments, AllowanceService $allowance): void
    {
        $agent = User::where('role', Role::Reservation)->firstOrFail();
        $declined = new CardDetails('4000 0000 0000 0002', now()->addYear()->format('m/y'), '123');
        $accepted = new CardDetails('4242 4242 4242 4242', now()->addYear()->format('m/y'), '123');

        $flightNo = config('arcs.airline.code').'201';

        $reservation = Reservation::query()
            ->whereHas('flight', fn ($flight) => $flight->where('flight_no', $flightNo))
            ->orderBy('id')
            ->firstOrFail();

        $passengerPayload = $reservation->passengers->map(fn (Passenger $passenger) => [
            'cabin_class' => $passenger->cabin_class,
            'type' => $passenger->type,
        ])->all();

        $fareQuote = $fares->quote(
            $passengerPayload,
            origin: $reservation->flight->origin,
            destination: $reservation->flight->destination,
        );

        $failed = $payments->charge($declined, $fareQuote->totalCents, PaymentKind::Fare, $agent, reservation: $reservation);

        $succeeded = $payments->charge($accepted, $fareQuote->totalCents, PaymentKind::Fare, $agent, reservation: $reservation);

        $heavyBag = Bag::query()
            ->where('weight_kg', '>=', 30)
            ->orderByDesc('weight_kg')
            ->first();

        if ($heavyBag !== null) {
            $passenger = $heavyBag->passenger;
            $allowed = $allowance->for($passenger);
            $excessKg = max(0, (int) ceil($heavyBag->weight_kg - $allowed->kgPerBag));

            if ($excessKg > 0) {
                $excessQuote = $fares->quote([], ['extra_kg' => $excessKg]);
                $payments->charge($accepted, $excessQuote->totalCents, PaymentKind::ExcessBaggage, $agent, passenger: $passenger);
            }
        }

        if ($failed->status !== PaymentStatus::Failed || $succeeded->status !== PaymentStatus::Succeeded) {
            throw new \RuntimeException('PaymentSeeder: expected one failed and one successful fare charge.');
        }
    }
}
