@php
    use App\Support\Money;
    use Illuminate\Support\Carbon;

    $paidAt = null;
    if ($payment?->paid_at) {
        $viewer = $payment->takenBy;
        $tz = $viewer?->timezone() ?? 'UTC';
        $paidAt = Carbon::instance($payment->paid_at)->setTimezone($tz)->format('d M Y H:i');
        if ($viewer?->airport_code === null) {
            $paidAt .= ' UTC';
        }
    }
@endphp
@if ($payment)
**Amount paid:** {{ Money::format($payment->amount_cents) }}

{{ $payment->card_brand }} ••••{{ $payment->card_last4 }} · reference {{ $payment->gateway_reference }}@if ($paidAt) · {{ $paidAt }}@endif
@endif
