<x-app-layout>
    <x-page-header title="Review exchange" :subtitle="'PNR '.$reservation->pnr.' · Leg '.$leg">
        <a href="{{ route('reservation.exchange', [$reservation, $leg]) }}" class="arcs-btn-secondary">Change selection</a>
    </x-page-header>

    <div class="mx-auto max-w-3xl space-y-6">
        <x-panel title="Old vs new">
            <div class="grid gap-4 sm:grid-cols-2 text-sm">
                <div>
                    <p class="text-gray-500">Current</p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($preview['old_flights'] as $flight)
                            <li class="arcs-mono">{{ $flight['label'] }}</li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <p class="text-gray-500">New</p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($preview['new_flights'] as $flight)
                            <li class="arcs-mono font-medium">{{ $flight['label'] }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <p class="mt-4 text-sm text-gray-600">Passengers: {{ collect($preview['passengers'])->pluck('name')->implode(', ') }}</p>
        </x-panel>

        <x-panel title="Price">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-gray-500">Paid for this leg</dt>
                    <dd class="font-semibold">{{ \App\Support\Money::format($preview['paid_cents']) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">New fare</dt>
                    <dd class="font-semibold">{{ \App\Support\Money::format($preview['new_fare_cents']) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Fare difference</dt>
                    <dd class="font-semibold">{{ \App\Support\Money::format($preview['difference_cents']) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Change fee</dt>
                    <dd class="font-semibold">{{ \App\Support\Money::format($preview['fee_cents']) }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-gray-500">Total to pay</dt>
                    <dd class="text-lg font-bold">{{ \App\Support\Money::format($preview['charge_cents']) }}</dd>
                </div>
            </dl>
            @if ($preview['difference_not_refunded'])
                <p class="mt-4 rounded-[14px] px-4 py-3 text-sm" style="background: #FFF8E8; color: #5C4810;">
                    The fare difference is not refunded.
                </p>
            @endif
        </x-panel>

        <form method="POST" action="{{ route('reservation.exchange.confirm', [$reservation, $leg]) }}" class="space-y-6">
            @csrf
            @foreach ($passengerIds as $id)
                <input type="hidden" name="passenger_ids[]" value="{{ $id }}">
            @endforeach
            @foreach ($flightIds as $id)
                <input type="hidden" name="flight_ids[]" value="{{ $id }}">
            @endforeach

            <x-panel title="Seats (optional — auto-assigned if empty)">
                @foreach ($preview['new_flights'] as $flightRow)
                    @php $map = $seatMaps[$flightRow['id']] ?? collect(); @endphp
                    <div class="mb-4">
                        <p class="mb-2 text-sm font-medium arcs-mono">{{ $flightRow['flight_no'] }}</p>
                        @foreach ($preview['passengers'] as $p)
                            @if (($p['type'] ?? '') === 'Infant')
                                @continue
                            @endif
                            <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                                <span class="w-40">{{ $p['name'] }}</span>
                                <select name="seats[{{ $p['id'] }}][{{ $flightRow['id'] }}]" class="arcs-input">
                                    <option value="">Auto</option>
                                    @foreach ($map->filter(fn ($s) => ! $s['taken']) as $seat)
                                        <option value="{{ $seat['id'] }}">{{ $seat['seat_no'] }} ({{ $seat['cabin'] }})</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </x-panel>

            @if ($preview['charge_cents'] > 0)
                <x-panel title="Card">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <x-input-label for="card_number" value="Card number" />
                            <x-text-input id="card_number" name="card_number" class="mt-1 block w-full" value="4242 4242 4242 4242" required autocomplete="off" />
                        </div>
                        <div>
                            <x-input-label for="card_expiry" value="Expiry" />
                            <x-text-input id="card_expiry" name="card_expiry" class="mt-1 block w-full" value="12/30" required />
                        </div>
                        <div>
                            <x-input-label for="card_cvc" value="CVC" />
                            <x-text-input id="card_cvc" name="card_cvc" class="mt-1 block w-full" value="123" required />
                        </div>
                    </div>
                </x-panel>
            @endif

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('reservation.exchange', [$reservation, $leg]) }}" class="arcs-btn-secondary">Back</a>
                <x-primary-button>Confirm exchange</x-primary-button>
            </div>
        </form>
    </div>
</x-app-layout>
