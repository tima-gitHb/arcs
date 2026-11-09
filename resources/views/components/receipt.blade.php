{{--
    Shared payment receipt lines (R10 / C11 / upgrade).
    <x-receipt :payment="$payment" :lines="$quote->lines" />
    lines: list of {label, quantity?, amount_cents} — optional when only the payment total is known.
--}}
@props([
    'payment' => null,
    'lines' => [],
    'currency' => 'USD',
])

@php
    use App\Support\Money;
@endphp

<div {{ $attributes->merge(['class' => 'rounded-lg bg-[#F7FAFD] px-4 py-3']) }} data-receipt>
    @if (count($lines) > 0)
        <ul class="space-y-1 text-sm text-gray-800">
            @foreach ($lines as $line)
                <li class="flex items-start justify-between gap-3">
                    <span>
                        {{ $line['label'] }}
                        @if (($line['quantity'] ?? 1) > 1)
                            <span class="text-gray-500">× {{ $line['quantity'] }}</span>
                        @endif
                    </span>
                    <span class="font-medium">{{ Money::format((int) $line['amount_cents']) }}</span>
                </li>
            @endforeach
        </ul>
        <div class="mt-3 flex items-end justify-between border-t border-navy/10 pt-3">
            <p class="text-xs uppercase tracking-wide text-gray-500">Total</p>
            <p class="text-xl font-semibold text-navy">
                {{ Money::format($payment?->amount_cents ?? (int) array_sum(array_column($lines, 'amount_cents'))) }}
            </p>
        </div>
    @elseif ($payment)
        <div class="text-right">
            <p class="text-xs uppercase tracking-wide text-gray-500">Amount paid</p>
            <p class="mt-1 text-2xl font-semibold text-navy">{{ Money::format($payment->amount_cents) }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $currency }}</p>
        </div>
    @endif

    @if ($payment)
        <p class="mt-3 text-sm font-medium text-gray-800">
            {{ $payment->card_brand }} ••••{{ $payment->card_last4 }}
            · reference <span class="font-mono">{{ $payment->gateway_reference }}</span>
            @if ($payment->paid_at)
                · {{ $payment->paid_at->timezone(config('app.timezone'))->format('d M Y H:i') }}
            @endif
        </p>
    @endif
</div>
