<x-mail::message>
# {{ $flight->flight_no }} to {{ $city }}

@if ($kind === 'gate')
The gate for {{ $flight->flight_no }} is now {{ $flight->gate }}.
@else
{{ $flight->flight_no }} is delayed. The new departure, in local time at {{ $flight->origin }}, is <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" />.
@endif

Please give this update to the passengers on PNR {{ $reservation->pnr }}.
</x-mail::message>
