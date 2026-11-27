<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Find reservation" subtitle="Search by PNR or browse upcoming flights.">
            <a href="{{ route('reservation.create') }}" class="arcs-btn-secondary">New reservation</a>
        </x-page-header>
    </x-slot>

    <div>
        <div class="grid items-start gap-6 lg:grid-cols-2">
            <div>
                <x-panel>
                    <form method="GET" action="{{ route('reservation.search') }}">
                        <x-input-label for="pnr" value="PNR" />
                        <div class="mt-1 flex flex-wrap items-center gap-3">
                            <x-text-input
                                id="pnr"
                                name="pnr"
                                type="text"
                                :value="$pnr"
                                class="w-48 uppercase tracking-[0.2em] arcs-mono"
                                maxlength="6"
                                autocomplete="off"
                                autofocus
                                placeholder="K7M2NP"
                            />
                            <button type="submit" class="arcs-btn-primary">
                                Find
                            </button>
                        </div>
                        <p class="mt-2 text-sm text-gray-500">Six letters and numbers. 0, 1, I and O are not used.</p>
                    </form>
                </x-panel>

                @if ($error)
                    <div class="mt-4 rounded-[14px] border px-4 py-3 text-sm" style="border-color: var(--arcs-error); color: var(--arcs-error); background: #FCE8E6;" role="alert">
                        {{ $error }}
                    </div>
                @endif

                @if (session('refund_receipt'))
                    @php $receipt = session('refund_receipt'); @endphp
                    <div class="mt-4 rounded-[14px] border px-4 py-4" style="border-color: var(--arcs-success); background: var(--arcs-success-bg);" role="status">
                        <p class="text-sm font-semibold" style="color: var(--arcs-success);">Refund receipt</p>
                        <p class="mt-1 text-lg font-bold text-gray-900">
                            Refunded {{ \App\Support\Money::format($receipt['amount_cents']) }}
                            @if (! empty($receipt['card_brand']))
                                to {{ $receipt['card_brand'] }} ••••{{ $receipt['card_last4'] }}
                            @endif
                        </p>
                        <p class="mt-1 text-sm text-gray-600">Cancellation fee {{ \App\Support\Money::format($receipt['fee_cents'] ?? 0) }}</p>
                    </div>
                @endif

                @if ($reservation)
                    <div class="mt-4">
                        @include('reservation.partials.summary', ['reservation' => $reservation])
                    </div>
                @endif
            </div>

            <div>
                <x-panel title="Flights">
                    @if ($flights->isEmpty())
                        <p class="text-sm text-gray-600">No flights yet.</p>
                    @else
                        <div class="mb-3">
                            <label for="flight-filter" class="text-sm font-medium text-gray-700">Filter flights</label>
                            <input
                                id="flight-filter"
                                type="search"
                                placeholder="Flight, city, or date"
                                class="arcs-input mt-1"
                                autocomplete="off"
                            >
                        </div>
                        <div id="flight-list" class="relative max-h-80 overflow-y-auto rounded-lg border" style="border-color: var(--arcs-border);">
                            @foreach ($flights->groupBy(fn ($flight) => $flight->departureLocal()->format('Y-m-d')) as $day => $dayFlights)
                                <div data-flight-day>
                                    <p class="sticky top-0 border-b px-4 py-1.5 text-xs font-medium uppercase tracking-wide text-gray-500" style="border-color: var(--arcs-border); background: var(--arcs-page);">
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
                                               'flex items-center justify-between gap-3 border-b px-4 py-2 text-sm last:border-b-0',
                                               'text-white' => $selectedFlight?->is($flight),
                                               'text-gray-900 hover:bg-[var(--arcs-page)]' => ! $selectedFlight?->is($flight),
                                           ])
                                           style="border-color: var(--arcs-border); {{ $selectedFlight?->is($flight) ? 'background: var(--arcs-side-active);' : '' }}">
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
                        <p id="flight-filter-empty" class="mt-2 hidden text-sm text-gray-600">No flights match.</p>
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
                </x-panel>
            </div>
        </div>

        @if ($selectedFlight)
            <x-panel class="mt-8" :title="'Reservations on '.$selectedFlight->flight_no">
                @if ($selectedFlight->reservations->isEmpty())
                    <p class="text-sm text-gray-600">No reservations on this flight.</p>
                @else
                    <div class="grid items-start gap-4 lg:grid-cols-2">
                        @foreach ($selectedFlight->reservations as $flightReservation)
                            <div class="min-w-0">
                                @include('reservation.partials.summary', ['reservation' => $flightReservation])
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-panel>
        @else
            <p class="mt-6 text-sm text-gray-600">Choose a flight to see its reservations.</p>
        @endif
    </div>
</x-app-layout>
