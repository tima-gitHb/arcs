<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Find reservation</h1>
    </x-slot>

    <div>
        <div class="grid items-start gap-6 lg:grid-cols-2">
            <div>
                <form method="GET" action="{{ route('reservation.search') }}" class="rounded-lg bg-white p-6 shadow-sm">
                    <x-input-label for="pnr" value="PNR" />
                    <div class="mt-1 flex flex-wrap items-center gap-3">
                        <x-text-input
                            id="pnr"
                            name="pnr"
                            type="text"
                            :value="$pnr"
                            class="w-48 uppercase tracking-[0.2em]"
                            maxlength="6"
                            autocomplete="off"
                            autofocus
                            placeholder="K7M2NP"
                        />
                        <button type="submit" class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">
                            Find
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-gray-500">Six letters and numbers. 0, 1, I and O are not used.</p>
                </form>

                @if ($error)
                    <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                        {{ $error }}
                    </div>
                @endif

                @if ($reservation)
                    <div class="mt-4">
                        @include('reservation.partials.summary', ['reservation' => $reservation])
                    </div>
                @endif
            </div>

            <div>
                <h2 class="text-lg font-semibold text-navy">Flights</h2>

                @if ($flights->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">No flights yet.</p>
                @else
                    <div class="mt-3 rounded-lg border border-gray-200 bg-white">
                        <div class="border-b border-gray-200 p-3">
                            <label for="flight-filter" class="text-sm font-medium text-gray-700">Filter flights</label>
                            <input
                                id="flight-filter"
                                type="search"
                                placeholder="Flight, city, or date"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                autocomplete="off"
                            >
                        </div>
                        <div id="flight-list" class="relative max-h-80 overflow-y-auto">
                            @foreach ($flights->groupBy(fn ($flight) => $flight->departureLocal()->format('Y-m-d')) as $day => $dayFlights)
                                <div data-flight-day>
                                    <p class="sticky top-0 border-b border-gray-100 bg-gray-50 px-4 py-1.5 text-xs font-medium uppercase tracking-wide text-gray-500">
                                        {{ \Illuminate\Support\Carbon::parse($day)->format('d M Y') }}
                                    </p>
                                    @foreach ($dayFlights as $flight)
                                        @php
                                            $when = $flight->departureLocal();
                                            $search = strtolower(implode(' ', [
                                                $flight->flight_no,
                                                $flight->origin,
                                                $flight->destination,
                                                $when->format('d M Y H:i'),
                                                $flight->status->label(),
                                            ]));
                                        @endphp
                                        <a href="{{ route('reservation.search', array_filter(['flight' => $flight->id, 'pnr' => $pnr !== '' ? $pnr : null])) }}"
                                           data-flight-search="{{ $search }}"
                                           @if ($selectedFlight?->is($flight)) data-selected="1" @endif
                                           @class([
                                               'flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2 text-sm last:border-b-0',
                                               'bg-navy text-white' => $selectedFlight?->is($flight),
                                               'text-gray-900 hover:bg-gray-50' => ! $selectedFlight?->is($flight),
                                           ])>
                                            <span>
                                                <span class="font-medium">{{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}</span>
                                                <span @class(['mt-0.5 block', 'text-white/80' => $selectedFlight?->is($flight), 'text-gray-500' => ! $selectedFlight?->is($flight)])>
                                                    {{ $when->format('H:i') }} · {{ $flight->status->label() }}
                                                </span>
                                            </span>
                                            <span @class(['shrink-0', 'text-white/80' => $selectedFlight?->is($flight), 'text-gray-500' => ! $selectedFlight?->is($flight)])>
                                                {{ $flight->reservations_count }} {{ $flight->reservations_count === 1 ? 'reservation' : 'reservations' }}
                                            </span>
                                        </a>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        <p id="flight-filter-empty" class="hidden border-t border-gray-100 px-4 py-3 text-sm text-gray-600">No flights match.</p>
                    </div>
                    <script>
                        const flightFilter = document.getElementById('flight-filter');
                        const flightRows = [...document.querySelectorAll('[data-flight-search]')];
                        const flightDays = [...document.querySelectorAll('[data-flight-day]')];
                        const flightFilterEmpty = document.getElementById('flight-filter-empty');
                        const flightList = document.getElementById('flight-list');
                        const selectedFlightRow = flightList.querySelector('[data-selected="1"]');

                        if (selectedFlightRow) {
                            flightList.scrollTop = selectedFlightRow.offsetTop - flightList.offsetTop;
                        }

                        flightFilter.addEventListener('input', () => {
                            const query = flightFilter.value.trim().toLowerCase();
                            let shown = 0;

                            flightRows.forEach((row) => {
                                const match = query === '' || row.dataset.flightSearch.includes(query);
                                row.classList.toggle('hidden', !match);

                                if (match) {
                                    shown++;
                                }
                            });

                            flightDays.forEach((day) => {
                                const any = [...day.querySelectorAll('[data-flight-search]')].some((row) => !row.classList.contains('hidden'));
                                day.classList.toggle('hidden', !any);
                            });

                            flightFilterEmpty.classList.toggle('hidden', shown !== 0);
                        });
                    </script>
                @endif
            </div>
        </div>

        @if ($selectedFlight)
            <h2 class="mt-8 text-lg font-semibold text-navy">Reservations on {{ $selectedFlight->flight_no }}</h2>

            @if ($selectedFlight->reservations->isEmpty())
                <p class="mt-3 text-sm text-gray-600">No reservations on this flight.</p>
            @else
                <div class="mt-3 grid items-start gap-4 lg:grid-cols-2">
                    @foreach ($selectedFlight->reservations as $flightReservation)
                        <div class="min-w-0">
                            @include('reservation.partials.summary', ['reservation' => $flightReservation])
                        </div>
                    @endforeach
                </div>
            @endif
        @else
            <p class="mt-6 text-sm text-gray-600">Choose a flight to see its reservations.</p>
        @endif
    </div>
</x-app-layout>
