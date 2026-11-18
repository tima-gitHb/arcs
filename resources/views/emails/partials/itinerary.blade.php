@php
    use App\Enums\SegmentDirection;
    use App\Support\ConnectionTime;

    $segments = $reservation->relationLoaded('segments')
        ? $reservation->segments
        : $reservation->load('segments.flight')->segments;
    $hasReturn = $segments->contains(fn ($segment) => $segment->direction === SegmentDirection::Return);
    $showDirectionHeaders = $hasReturn || $segments->count() > 1;

    if (! $reservation->relationLoaded('passengers')
        || $reservation->passengers->contains(fn ($p) => ! $p->relationLoaded('coupons'))) {
        $reservation->load('passengers.coupons.seatAssignment.seat');
    }
@endphp

@foreach ([SegmentDirection::Outbound, SegmentDirection::Return] as $direction)
@php $legs = $segments->where('direction', $direction)->values(); @endphp
@if ($legs->isNotEmpty())
@if ($showDirectionHeaders)
### {{ $direction->label() }}

@endif
@foreach ($legs as $index => $segment)
@php
    $flight = $segment->flight;
    $previous = $index > 0 ? $legs[$index - 1]->flight : null;
    $connection = $previous ? ConnectionTime::label($previous, $flight) : null;
    $dep = $flight->departureLocal()?->format('d M Y H:i');
    $arr = $flight->arrivalLocal()?->format('d M Y H:i');
@endphp
@if ($connection)
*Connection at {{ $previous->destination }}: {{ $connection }}*

@endif
**{{ $flight->flight_no }}** · {{ $flight->origin }} → {{ $flight->destination }}
- Departure ({{ $flight->origin }} local): {{ $dep ?? '—' }}
- Arrival ({{ $flight->destination }} local): {{ $arr ?? '—' }}
- Seats:
@foreach ($reservation->passengers as $passenger)
@php
    $coupon = $passenger->coupons->firstWhere('flight_id', $flight->id);
    $seatNo = $coupon?->seatAssignment?->seat?->seat_no;
@endphp
@if ($passenger->type === App\Enums\PassengerType::Infant)
  - {{ $passenger->first_name }} {{ $passenger->last_name }}: No seat
@elseif ($seatNo)
  - {{ $passenger->first_name }} {{ $passenger->last_name }}: {{ $seatNo }}
@else
  - {{ $passenger->first_name }} {{ $passenger->last_name }}: Not assigned
@endif
@endforeach

@endforeach
@endif
@endforeach
