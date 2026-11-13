<x-mail::message>
# E-ticket {{ $reservation->pnr }}

Booking confirmed. Give this PNR to the passenger for check-in.

**Flight:** {{ $reservation->flight->flight_no }} · {{ $reservation->flight->origin }} → {{ $reservation->flight->destination }}

**Departure ({{ $reservation->flight->origin }} local time):** {{ $reservation->flight->departureLocal()->format('d M Y H:i') }}

<x-receipt :payment="$payment" class="my-4" />

## Passengers

@foreach ($reservation->passengers as $passenger)
@php
    $parts = [$passenger->type->label()];
    if ($passenger->cabin_class) {
        $parts[] = $passenger->cabin_class->label();
    }
    if ($passenger->seatAssignment?->seat) {
        $parts[] = 'seat '.$passenger->seatAssignment->seat->seat_no;
    }
@endphp
- {{ $passenger->first_name }} {{ $passenger->last_name }} ({{ implode(', ', $parts) }})
@endforeach

{{ config('app.name') }}
</x-mail::message>
