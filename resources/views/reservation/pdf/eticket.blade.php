{{-- PDF e-ticket for email attachment (plain CSS — dompdf). Expects $reservation, $payment. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>E-ticket {{ $reservation->pnr }}</title>
    <style>
        @page { margin: 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; }
        h1 { font-size: 16pt; margin: 0 0 4mm; color: #0B3A5B; }
        h2 { font-size: 12pt; margin: 6mm 0 2mm; color: #0B3A5B; }
        h3 { font-size: 10pt; margin: 4mm 0 2mm; text-transform: uppercase; letter-spacing: 0.5px; color: #0B3A5B; }
        .muted { color: #555; font-size: 9pt; }
        .pnr { font-size: 20pt; letter-spacing: 3px; font-weight: bold; color: #0B3A5B; }
        table { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        th, td { text-align: left; padding: 1.5mm 2mm; vertical-align: top; border-bottom: 1px solid #ddd; }
        th { color: #555; font-weight: normal; font-size: 8pt; text-transform: uppercase; }
        .leg { margin: 3mm 0; padding: 2mm 0; border-bottom: 1px solid #eee; }
        .connection { margin: 2mm 0; padding: 1.5mm 2mm; border: 1px dashed #0B3A5B; font-size: 9pt; color: #0B3A5B; }
        .receipt { margin-top: 4mm; padding: 3mm; background: #F7FAFD; }
    </style>
</head>
<body>
@php
    use App\Enums\PassengerType;
    use App\Enums\SegmentDirection;
    use App\Support\ConnectionTime;
    use App\Support\Money;

    $segments = $reservation->segments;
    $hasReturn = $segments->contains(fn ($segment) => $segment->direction === SegmentDirection::Return);
    $showDirectionHeaders = $hasReturn || $segments->count() > 1;
@endphp

<p class="muted">ARCS e-ticket / receipt</p>
<h1 class="pnr">{{ $reservation->pnr }}</h1>
<p class="muted">Give this PNR to the passenger for check-in.</p>

@if ($payment)
    <div class="receipt">
        <strong>Amount paid:</strong> {{ Money::format($payment->amount_cents) }} USD<br>
        {{ $payment->card_brand }} ••••{{ $payment->card_last4 }}
        · reference {{ $payment->gateway_reference }}
    </div>
@endif

<h2>{{ $segments->count() > 1 ? 'Itinerary' : 'Flight' }}</h2>

@foreach ([SegmentDirection::Outbound, SegmentDirection::Return] as $direction)
    @php $legs = $segments->where('direction', $direction)->values(); @endphp
    @if ($legs->isEmpty())
        @continue
    @endif

    @if ($showDirectionHeaders)
        <h3>{{ $direction->label() }}</h3>
    @endif

    @foreach ($legs as $index => $segment)
        @php
            $flight = $segment->flight;
            $previous = $index > 0 ? $legs[$index - 1]->flight : null;
            $connection = $previous ? ConnectionTime::label($previous, $flight) : null;
            $dep = $flight->departureLocal()?->format('D d M Y H:i');
            $arr = $flight->arrivalLocal()?->format('D d M Y H:i');
        @endphp

        @if ($connection)
            <div class="connection">Connection at {{ $previous->destination }}: {{ $connection }}</div>
        @endif

        <div class="leg">
            <strong>{{ $flight->flight_no }}</strong>
            · {{ $flight->origin }} → {{ $flight->destination }}<br>
            Departure ({{ $flight->origin }} local): {{ $dep ?? '—' }}<br>
            Arrival ({{ $flight->destination }} local): {{ $arr ?? '—' }}<br>
            Seats:
            <ul>
                @foreach ($reservation->passengers as $passenger)
                    @php
                        $coupon = $passenger->coupons->firstWhere('flight_id', $flight->id);
                        $seatNo = $coupon?->seatAssignment?->seat?->seat_no;
                    @endphp
                    <li>
                        {{ $passenger->first_name }} {{ $passenger->last_name }}:
                        @if ($passenger->type === PassengerType::Infant)
                            No seat
                        @elseif ($seatNo)
                            {{ $seatNo }}
                        @else
                            Not assigned
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
@endforeach

@if ($reservation->contact_email || $reservation->contact_phone)
    <h2>Contact</h2>
    @if ($reservation->contact_email)
        <p>Email: {{ $reservation->contact_email }}</p>
    @endif
    @if ($reservation->contact_phone)
        <p>Phone: {{ $reservation->contact_phone }}</p>
    @endif
@endif

<h2>Passengers</h2>
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Type</th>
            <th>Class</th>
            <th>Seat(s)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($reservation->passengers as $passenger)
            <tr>
                <td>{{ $passenger->first_name }} {{ $passenger->last_name }}</td>
                <td>{{ $passenger->type->label() }}</td>
                <td>{{ $passenger->cabin_class?->label() ?? '—' }}</td>
                <td>
                    @if ($passenger->type === PassengerType::Infant)
                        No seat
                    @elseif ($segments->count() > 1)
                        @foreach ($passenger->coupons as $coupon)
                            {{ $coupon->flight->flight_no }}: {{ $coupon->seatAssignment?->seat?->seat_no ?? 'Not assigned' }}@if (! $loop->last), @endif
                        @endforeach
                    @else
                        {{ $passenger->seatAssignment?->seat?->seat_no ?? 'Not assigned' }}
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
</body>
</html>
