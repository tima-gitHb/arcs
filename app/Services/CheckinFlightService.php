<?php

namespace App\Services;

use App\Enums\PassengerStatus;
use App\Models\Flight;
use App\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * Check-in by flight (C13): the reservations on one flight for the desk list.
 */
class CheckinFlightService
{
    /**
     * Reservations with a segment on this flight, optionally filtered by PNR or passenger name,
     * sorted by lead passenger last name then first name. Eager-loaded so the page stays a fixed
     * number of queries for any flight size.
     *
     * @return Collection<int, array{reservation: Reservation, lead: string, passengers: int, checked_in: int}>
     */
    public function reservations(Flight $flight, ?string $filter = null): Collection
    {
        $filter = trim((string) $filter);

        $reservations = Reservation::query()
            ->whereHas('segments', fn ($query) => $query->where('flight_id', $flight->id))
            ->with([
                'passengers' => fn ($query) => $query
                    ->whereHas('coupons', fn ($coupons) => $coupons
                        ->where('flight_id', $flight->id)
                        ->whereNotIn('status', [PassengerStatus::Cancelled->value, PassengerStatus::Exchanged->value]))
                    ->with(['coupon' => fn ($coupon) => $coupon->where('coupons.flight_id', $flight->id)])
                    ->orderBy('id'),
            ])
            ->when($filter !== '', function ($query) use ($filter, $flight) {
                $like = '%'.$filter.'%';
                $query->where(function ($match) use ($like, $filter, $flight) {
                    $match->where('pnr', 'like', strtoupper($filter))
                        ->orWhereHas('passengers', function ($passengers) use ($like, $flight) {
                            $passengers
                                ->whereHas('coupons', fn ($coupons) => $coupons
                                    ->where('flight_id', $flight->id)
                                    ->whereNotIn('status', [PassengerStatus::Cancelled->value, PassengerStatus::Exchanged->value]))
                                ->where(function ($name) use ($like) {
                                    $name->where('last_name', 'like', $like)
                                        ->orWhere('first_name', 'like', $like);
                                });
                        });
                });
            })
            ->get();

        return $reservations
            ->map(function (Reservation $reservation) {
                $passengers = $reservation->passengers;
                // Lead = first booked on the PNR (lowest id); the list sorts by that name.
                $lead = $passengers->sortBy('id')->first();
                $checkedIn = $passengers->filter(function ($passenger) {
                    $status = $passenger->coupon?->status ?? $passenger->status;

                    return in_array($status, [
                        PassengerStatus::CheckedIn,
                        PassengerStatus::Boarded,
                        PassengerStatus::NoShow,
                    ], true);
                })->count();

                return [
                    'reservation' => $reservation,
                    'lead' => $lead ? $lead->first_name.' '.$lead->last_name : '—',
                    'lead_sort' => $lead ? strtolower($lead->last_name.' '.$lead->first_name) : '',
                    'passengers' => $passengers->count(),
                    'checked_in' => $checkedIn,
                ];
            })
            ->filter(fn (array $row) => $row['passengers'] > 0)
            ->sortBy('lead_sort')
            ->values();
    }
}
