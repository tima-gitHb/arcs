<x-mail::message>
# E-ticket {{ $reservation->pnr }}

Booking confirmed. Give this PNR to the passenger for check-in.

@if (! empty($changeNote))
{{ $changeNote }}
@endif

@include('emails.partials.itinerary', ['reservation' => $reservation])

@include('emails.partials.payment', ['payment' => $payment])

## Passengers

@foreach ($reservation->passengers as $passenger)
@php
    $parts = [$passenger->type->label()];
    if ($passenger->cabin_class) {
        $parts[] = $passenger->cabin_class->label();
    }
    if ($reservation->segments->count() > 1) {
        $seats = $passenger->coupons->map(function ($coupon) {
            $seat = $coupon->seatAssignment?->seat?->seat_no;

            return $coupon->flight->flight_no.': '.($seat ?? 'not assigned');
        })->implode(', ');
        if ($seats !== '') {
            $parts[] = $seats;
        }
    } elseif ($passenger->seatAssignment?->seat) {
        $parts[] = 'seat '.$passenger->seatAssignment->seat->seat_no;
    }
@endphp
- {{ $passenger->first_name }} {{ $passenger->last_name }} ({{ implode(', ', $parts) }})
@endforeach
</x-mail::message>
