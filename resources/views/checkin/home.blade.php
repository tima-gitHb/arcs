<x-app-layout>
    <x-slot name="search">
        <form method="GET" action="{{ route('checkin.home') }}" class="flex max-w-md flex-wrap items-center gap-2">
            <label for="q" class="sr-only">PNR or last name</label>
            <input
                id="q"
                name="q"
                type="text"
                value="{{ $search }}"
                autocomplete="off"
                autofocus
                placeholder="PNR or last name"
                class="w-full min-w-[10rem] flex-1 rounded-lg border-0 bg-white/10 px-3 py-2 text-sm text-white placeholder:text-white/50 focus:bg-white/15 focus:ring-1 focus:ring-white/40"
            />
            <button type="submit" class="arcs-btn-primary min-h-[40px] px-3 py-2 text-sm">Find</button>
        </form>
    </x-slot>

    <x-page-header title="Check in passenger" subtitle="Find a PNR or last name, then open the passenger." />

    <x-step-bar class="mb-6" :steps="['Find', 'Documents', 'Bags', 'Seat', 'Confirm']" :current="0" />

    <div class="space-y-6">
        @if ($search !== '' && $reservations->isEmpty())
            <x-empty-state message='Reservation not found for “{{ $search }}”.' />
        @endif

        @foreach ($reservations as $reservation)
            @php($flight = $reservation->flight)
            @php($windowOpen = $flight->isCheckinWindowOpen())

            <x-panel>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b pb-3" style="border-color: var(--arcs-border);">
                    <div>
                        <span class="arcs-mono text-lg font-semibold tracking-[0.15em]" style="color: var(--arcs-accent-text);">{{ $reservation->pnr }}</span>
                        <span class="ml-2 text-sm text-gray-700">
                            {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}
                            · <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M H:i" />
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-status-badge :status="$flight->status" />
                        <x-status-badge :label="$windowOpen ? 'Window open' : 'Window closed'" :status="$windowOpen ? 'CheckinOpen' : 'Closed'" />
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    (<x-local-time :at="$flight->checkin_opens_at" :airport="$flight->origin" format="H:i" />–<x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="H:i" />)
                </p>

                @if ($reservation->passengers->count() > 1)
                    <div class="flex justify-end border-b py-2" style="border-color: var(--arcs-border);">
                        <a href="{{ route('checkin.reservations.group', $reservation) }}" class="arcs-btn-primary text-sm">Check in all</a>
                    </div>
                @endif

                <ul class="divide-y" style="border-color: var(--arcs-border);">
                    @foreach ($reservation->passengers as $passenger)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" style="border-color: var(--arcs-border);">
                            <div>
                                <span class="font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</span>
                                <span class="ml-1 text-gray-500">{{ $passenger->type->value }}</span>
                                <x-status-badge class="ml-2" :status="$passenger->status" />
                            </div>

                            @if ($passenger->status === \App\Enums\PassengerStatus::Booked)
                                <a href="{{ route('checkin.passengers.show', $passenger) }}" class="arcs-btn-primary text-sm">Check in</a>
                            @elseif ($passenger->status === \App\Enums\PassengerStatus::CheckedIn)
                                <a href="{{ route('checkin.passengers.show', $passenger) }}" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Open</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-panel>
        @endforeach

        <x-todays-flights :role="'checkin'" />
    </div>
</x-app-layout>
