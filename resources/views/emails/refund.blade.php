<x-mail::message>
# Refund {{ $reservation->pnr }}

Your booking has been partially or fully cancelled. A refund has been processed to the card used at booking.

@include('emails.partials.payment', [
    'payment' => $refund ?? $original,
    'amountOverride' => $preview['refund_cents'] ?? null,
    'label' => 'Amount refunded',
])

**Cancellation fee:** {{ \App\Support\Money::format($preview['fee_cents'] ?? 0) }}

## Cancelled

@foreach ($preview['lines'] ?? [] as $line)
- {{ $line['passenger_name'] }} · {{ $line['flight_no'] }} (leg {{ $line['leg'] }})
@endforeach

@include('emails.partials.itinerary', ['reservation' => $reservation])
</x-mail::message>
