<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Check in passenger</h1>
    </x-slot>

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <div>
            <form method="GET" action="{{ route('checkin.home') }}" class="rounded-lg bg-white p-6 shadow-sm">
                <x-input-label for="q" value="PNR or last name" />
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <x-text-input
                        id="q"
                        name="q"
                        type="text"
                        :value="$search"
                        class="w-64"
                        autocomplete="off"
                        autofocus
                        placeholder="PNR or last name"
                    />
                    <button type="submit" class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">
                        Find
                    </button>
                </div>
            </form>

            @if ($search !== '' && $reservations->isEmpty())
                <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="status">
                    Reservation not found for “{{ $search }}”.
                </div>
            @endif

            @foreach ($reservations as $reservation)
                @php($flight = $reservation->flight)
                @php($windowOpen = $flight->isCheckinWindowOpen())

                <div class="mt-4 rounded-lg bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3">
                        <div>
                            <span class="font-mono text-lg font-semibold tracking-[0.2em] text-navy">{{ $reservation->pnr }}</span>
                            <span class="ml-2 text-sm text-gray-700">
                                {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}
                                · <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M H:i" />
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs font-medium">
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-gray-700">{{ $flight->status->label() }}</span>
                            <span @class([
                                'rounded-full px-2.5 py-0.5',
                                'bg-green-100 text-green-800' => $windowOpen,
                                'bg-red-100 text-red-800' => ! $windowOpen,
                            ]) title="Check-in {{ $flight->atOrigin($flight->checkin_opens_at)->format('H:i') }}–{{ $flight->atOrigin($flight->checkin_closes_at)->format('H:i') }} local time">
                                {{ $windowOpen ? 'Window open' : 'Window closed' }}
                                (<x-local-time :at="$flight->checkin_opens_at" :airport="$flight->origin" format="H:i" />–<x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="H:i" />)
                            </span>
                        </div>
                    </div>

                    @if ($reservation->passengers->count() > 1)
                        <div class="flex justify-end border-b border-gray-100 px-4 py-2">
                            <a href="{{ route('checkin.reservations.group', $reservation) }}"
                               class="rounded bg-navy px-3 py-1.5 text-sm font-medium text-white hover:bg-navy/90">
                                Check in all
                            </a>
                        </div>
                    @endif

                    <ul class="divide-y divide-gray-100">
                        @foreach ($reservation->passengers as $passenger)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div>
                                    <span class="font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</span>
                                    <span class="ml-1 text-gray-500">{{ $passenger->type->value }}</span>
                                    <span class="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $passenger->status->label() }}</span>
                                </div>

                                @if ($passenger->status === \App\Enums\PassengerStatus::Booked)
                                    {{-- Opens the check-in screen; the status changes only on "Confirm check-in". --}}
                                    <a href="{{ route('checkin.passengers.show', $passenger) }}" class="rounded bg-navy px-3 py-1.5 text-sm font-medium text-white hover:bg-navy/90">
                                        Check in
                                    </a>
                                @elseif ($passenger->status === \App\Enums\PassengerStatus::CheckedIn)
                                    <a href="{{ route('checkin.passengers.show', $passenger) }}" class="text-sm font-medium text-navy hover:underline">
                                        Open
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <x-todays-flights :role="'checkin'" />
    </div>
</x-app-layout>
