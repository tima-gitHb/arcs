<x-app-layout>
    <x-page-header title="Cancel and refund" :subtitle="'PNR '.$reservation->pnr">
        <a href="{{ route('reservation.search', ['pnr' => $reservation->pnr]) }}" class="arcs-btn-secondary">Back to booking</a>
    </x-page-header>

    <div class="mx-auto max-w-3xl space-y-6">
        @if ($reservation->isCancelled())
            <x-panel>
                <p class="text-sm text-gray-600">This booking is already fully cancelled.</p>
            </x-panel>
        @else
            <x-panel title="What to cancel">
                <p class="mb-4 text-sm text-gray-600">
                    Select passengers and, for multi-leg bookings, which later legs to drop.
                    Refund = paid for the cancelled part − cancellation fee (never below zero).
                </p>

                <form method="POST" action="{{ route('reservation.refund.preview', $reservation) }}" class="space-y-6">
                    @csrf

                    <div>
                        <p class="mb-2 text-sm font-medium text-gray-800">Passengers</p>
                        <div class="space-y-2">
                            @foreach ($reservation->passengers as $passenger)
                                @php
                                    $booked = $passenger->coupons->contains(fn ($c) => $c->status === \App\Enums\PassengerStatus::Booked);
                                @endphp
                                <label @class(['flex items-center gap-3 rounded-[14px] border px-4 py-3', 'opacity-50' => ! $booked]) style="border-color: var(--arcs-border);">
                                    <input
                                        type="checkbox"
                                        name="passenger_ids[]"
                                        value="{{ $passenger->id }}"
                                        class="rounded border-gray-300"
                                        @checked(in_array((string) $passenger->id, array_map('strval', old('passenger_ids', [])), true))
                                        @disabled(! $booked)
                                    >
                                    <span>
                                        <span class="font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</span>
                                        <span class="mt-0.5 block text-sm text-gray-500">{{ $passenger->type->label() }} · <x-status-badge :status="$passenger->status" /></span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('passenger_ids')" class="mt-2" />
                    </div>

                    @if (count($legNumbers) > 1)
                        <div>
                            <p class="mb-2 text-sm font-medium text-gray-800">Legs (leave empty = all remaining)</p>
                            <p class="mb-2 text-xs text-gray-500">Multi-city: only a later suffix can be dropped (e.g. leg 3 alone).</p>
                            <div class="space-y-2">
                                @foreach ($legNumbers as $leg)
                                    @php
                                        $seg = $reservation->segments->firstWhere('leg', $leg);
                                        $flight = $seg?->flight;
                                    @endphp
                                    <label class="flex items-center gap-3 rounded-[14px] border px-4 py-3" style="border-color: var(--arcs-border);">
                                        <input type="checkbox" name="legs[]" value="{{ $leg }}" class="rounded border-gray-300" @checked(in_array((string) $leg, array_map('strval', old('legs', [])), true))>
                                        <span class="text-sm">
                                            Leg {{ $leg }}
                                            @if ($flight)
                                                · {{ $flight->flight_no }} {{ $flight->origin }}→{{ $flight->destination }}
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($payment)
                        <p class="text-sm text-gray-600">
                            Original charge {{ \App\Support\Money::format($payment->amount_cents) }}
                            on {{ $payment->card_brand }} ••••{{ $payment->card_last4 }}
                            · refundable {{ \App\Support\Money::format($payment->refundableCents()) }}
                        </p>
                    @else
                        <p class="text-sm text-gray-600">No fare payment on this booking — cancel only (no refund).</p>
                    @endif

                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('reservation.search', ['pnr' => $reservation->pnr]) }}" class="arcs-btn-secondary">Back</a>
                        <x-primary-button>Review refund</x-primary-button>
                    </div>
                </form>
            </x-panel>
        @endif
    </div>
</x-app-layout>
