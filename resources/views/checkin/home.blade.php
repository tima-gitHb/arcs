{{-- Check-in find passenger — B14 example of <x-app-shell>. Other pages move later. --}}
<x-app-shell>
    <x-slot:search>
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
                class="w-full min-w-[10rem] flex-1 rounded border-0 bg-white/10 px-3 py-1.5 text-sm text-white placeholder:text-white/50 focus:bg-white/15 focus:ring-1 focus:ring-white/40"
            />
            <button type="submit" class="rounded px-3 py-1.5 text-sm font-medium" style="background: var(--arcs-accent); color: var(--arcs-on-accent);">
                Find
            </button>
        </form>
    </x-slot:search>

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Check in passenger</h1>
            <p class="mt-1 text-sm text-gray-600">Find a PNR or last name, then open the passenger.</p>
        </div>

        <x-step-bar :steps="['Find', 'Documents', 'Bags', 'Seat', 'Confirm']" :current="0" />

        @if ($search !== '' && $reservations->isEmpty())
            <div class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="status">
                Reservation not found for “{{ $search }}”.
            </div>
        @endif

        @foreach ($reservations as $reservation)
            @php($flight = $reservation->flight)
            @php($windowOpen = $flight->isCheckinWindowOpen())

            <x-panel>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3">
                    <div>
                        <span class="font-mono text-lg font-semibold tracking-[0.2em]" style="color: var(--arcs-accent-text);">{{ $reservation->pnr }}</span>
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
                        ])>
                            {{ $windowOpen ? 'Window open' : 'Window closed' }}
                            (<x-local-time :at="$flight->checkin_opens_at" :airport="$flight->origin" format="H:i" />–<x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="H:i" />)
                        </span>
                    </div>
                </div>

                @if ($reservation->passengers->count() > 1)
                    <div class="flex justify-end border-b border-gray-100 py-2">
                        <a href="{{ route('checkin.reservations.group', $reservation) }}"
                           class="rounded px-3 py-1.5 text-sm font-medium text-white hover:opacity-90"
                           style="background: var(--arcs-accent);">
                            Check in all
                        </a>
                    </div>
                @endif

                <ul class="divide-y divide-gray-100">
                    @foreach ($reservation->passengers as $passenger)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                            <div>
                                <span class="font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</span>
                                <span class="ml-1 text-gray-500">{{ $passenger->type->value }}</span>
                                <span class="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $passenger->status->label() }}</span>
                            </div>

                            @if ($passenger->status === \App\Enums\PassengerStatus::Booked)
                                <a href="{{ route('checkin.passengers.show', $passenger) }}"
                                   class="rounded px-3 py-1.5 text-sm font-medium text-white hover:opacity-90"
                                   style="background: var(--arcs-accent);">
                                    Check in
                                </a>
                            @elseif ($passenger->status === \App\Enums\PassengerStatus::CheckedIn)
                                <a href="{{ route('checkin.passengers.show', $passenger) }}" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">
                                    Open
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-panel>
        @endforeach

        <x-todays-flights :role="'checkin'" />
    </div>
</x-app-shell>
