@php
    $selectedPassengers = old('passenger_ids', $passengerIds ?? []);
@endphp
<x-app-layout>
    <x-page-header title="Change this leg" :subtitle="'PNR '.$reservation->pnr.' · Leg '.$leg">
        <a href="{{ route('reservation.search', ['pnr' => $reservation->pnr]) }}" class="arcs-btn-secondary">Back to booking</a>
    </x-page-header>

    <div class="mx-auto max-w-3xl space-y-6">
        <x-panel title="Current leg">
            <ul class="space-y-1 text-sm">
                @foreach ($legFlights as $flight)
                    <li class="arcs-mono">
                        {{ $flight->flight_no }} · {{ $flight->origin }}→{{ $flight->destination }}
                        · <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M Y H:i" />
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-gray-500">Same route only — pick another date or flight, not a new destination.</p>
        </x-panel>

        <x-panel title="Passengers and date">
            <form method="POST" action="{{ route('reservation.exchange.search', [$reservation, $leg]) }}" class="space-y-4">
                @csrf
                <div class="space-y-2">
                    @foreach ($reservation->passengers as $passenger)
                        @php
                            $onLeg = $passenger->coupons->contains(fn ($c) => (int) ($c->segment?->leg ?? 1) === (int) $leg && $c->status === \App\Enums\PassengerStatus::Booked);
                        @endphp
                        <label @class(['flex items-center gap-3 rounded-[14px] border px-4 py-3', 'opacity-50' => ! $onLeg]) style="border-color: var(--arcs-border);">
                            <input type="checkbox" name="passenger_ids[]" value="{{ $passenger->id }}" class="rounded border-gray-300"
                                @checked(in_array((string) $passenger->id, array_map('strval', $selectedPassengers), true))
                                @disabled(! $onLeg)>
                            <span>
                                <span class="font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</span>
                                <span class="mt-0.5 block text-sm text-gray-500">{{ $passenger->type->label() }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <div>
                    <x-input-label for="date" value="New departure date" />
                    <x-text-input id="date" name="date" type="date" class="mt-1 block w-full" :value="old('date', $date)" required />
                </div>
                <x-primary-button>Find flights</x-primary-button>
            </form>
        </x-panel>

        @if ($date !== null)
            <x-panel title="Flights on {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}">
                @forelse ($candidates as $flight)
                    <form method="POST" action="{{ route('reservation.exchange.review', [$reservation, $leg]) }}" class="mb-3 rounded-[14px] border p-4" style="border-color: var(--arcs-border);">
                        @csrf
                        @foreach ($selectedPassengers as $id)
                            <input type="hidden" name="passenger_ids[]" value="{{ $id }}">
                        @endforeach
                        <input type="hidden" name="flight_ids[]" value="{{ $flight->id }}">
                        <input type="hidden" name="date" value="{{ $date }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold arcs-mono">{{ $flight->flight_no }}</p>
                                <p class="text-sm text-gray-600">
                                    {{ $flight->origin }}→{{ $flight->destination }}
                                    · <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" />
                                    · {{ $flight->status->label() }}
                                </p>
                            </div>
                            <x-primary-button>Select</x-primary-button>
                        </div>
                    </form>
                @empty
                    <p class="text-sm text-gray-600">No bookable flights on this route for that date.</p>
                @endforelse
            </x-panel>
        @endif
    </div>
</x-app-layout>
