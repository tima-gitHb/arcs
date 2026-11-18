<?php

namespace App\Services;

use App\Enums\PassengerStatus;
use App\Mail\FlightChangeMail;
use App\Models\Flight;
use App\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails reservation contacts after a gate change or a delay (M16).
 * One message per reservation, not per passenger. A failure is logged and never thrown:
 * the gate or the new departure is already saved.
 */
class FlightNotifier
{
    public function gateChanged(Flight $flight): void
    {
        $this->send($flight, 'gate');
    }

    public function delayed(Flight $flight): void
    {
        $this->send($flight, 'delay');
    }

    /**
     * Reservations with at least one Booked or CheckedIn coupon on this flight and an email.
     * Cancelled, Boarded and NoShow coupons do not qualify.
     *
     * @return Collection<int, Reservation>
     */
    private function recipients(Flight $flight): Collection
    {
        return Reservation::query()
            ->whereNotNull('contact_email')
            ->where('contact_email', '!=', '')
            ->whereHas('passengers.coupons', function ($query) use ($flight) {
                $query->where('coupons.flight_id', $flight->id)
                    ->whereIn('coupons.status', [
                        PassengerStatus::Booked->value,
                        PassengerStatus::CheckedIn->value,
                    ]);
            })
            ->orderBy('id')
            ->get();
    }

    private function send(Flight $flight, string $kind): void
    {
        foreach ($this->recipients($flight) as $reservation) {
            try {
                Mail::to($reservation->contact_email)->queue(new FlightChangeMail($reservation, $flight, $kind));
            } catch (Throwable $e) {
                Log::error("Flight {$flight->flight_no} notification could not be queued.", [
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
