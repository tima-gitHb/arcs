<x-app-layout>
    <x-page-header :title="$flight->flight_no" :subtitle="$flight->origin.' → '.$flight->destination.' · gate '.($flight->gate ?? '—').' · '.$flight->aircraft->registration">
        <x-slot:breadcrumb>
            <span class="text-gray-400">Flights and aircraft</span>
            <span class="text-gray-300">›</span>
            <a href="{{ route('manager.flights') }}" class="hover:underline">Flights</a>
            <span class="text-gray-300">›</span>
            <span class="arcs-mono">{{ $flight->flight_no }}</span>
        </x-slot:breadcrumb>
        <x-status-badge :status="$flight->status" />
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-4 text-sm text-gray-600">
        <span><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M Y H:i" /></span>
        @if ($flight->arrives_at)
            <span>Arrives <x-local-time :at="$flight->arrives_at" :airport="$flight->destination" format="d M Y H:i" /></span>
        @endif
    </div>

    @if ($errors->has('status'))
        <div class="mb-4 rounded-lg border px-4 py-3 text-sm" style="border-color: #F5C2C0; background: #FCE8E6; color: var(--arcs-error);" role="alert">
            {{ $errors->first('status') }}
        </div>
    @endif
    @if ($errors->has('departure_at'))
        <div class="mb-4 rounded-lg border px-4 py-3 text-sm" style="border-color: #F5C2C0; background: #FCE8E6; color: var(--arcs-error);" role="alert">
            {{ $errors->first('departure_at') }}
        </div>
    @endif

    <div class="mb-6 flex flex-wrap gap-2 border-b pb-3" style="border-color: var(--arcs-border);">
        @foreach (['overview' => 'Overview', 'load' => 'Load and seats', 'status' => 'Status'] as $key => $label)
            <a href="{{ route('manager.flights.show', ['flight' => $flight, 'tab' => $key]) }}"
                class="rounded-full px-3 py-1.5 text-sm {{ $tab === $key ? 'font-semibold text-white' : 'text-gray-600' }}"
                style="{{ $tab === $key ? 'background: var(--arcs-bar)' : 'background: #EEF0F4' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if ($tab === 'overview')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-[22px] border bg-white px-5 py-4 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="text-sm text-gray-500">Booked</p>
                <p class="mt-1 text-2xl font-bold">{{ $counters['booked'] }}</p>
            </div>
            <div class="rounded-[22px] border bg-white px-5 py-4 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="text-sm text-gray-500">Checked in</p>
                <p class="mt-1 text-2xl font-bold">{{ $counters['checked_in'] }}</p>
            </div>
            <div class="rounded-[22px] border bg-white px-5 py-4 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="text-sm text-gray-500">Bags</p>
                <p class="mt-1 text-2xl font-bold">{{ $counters['bags'] }}</p>
            </div>
            <div class="rounded-[22px] border bg-white px-5 py-4 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="text-sm text-gray-500">Load</p>
                <p class="mt-1 text-2xl font-bold">{{ $counters['load'] }} %</p>
            </div>
        </div>
        <x-panel class="mt-6" title="Check-in window">
            <p class="text-sm text-gray-700">
                <x-local-time :at="$flight->checkin_opens_at" :airport="$flight->origin" format="d M Y H:i" />
                –
                <x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="d M Y H:i" />
            </p>
        </x-panel>
    @endif

    @if ($tab === 'load')
        <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
            <x-seat-map hint="Read-only · sold and checked-in seats" mode="gate"
                :data-seats="json_encode($seatMap)"
                :data-seat-states="json_encode($seatStates)" />
            <div class="space-y-4">
                @foreach ($cabinLoad as $cabin => $stats)
                    @php $pct = $stats['seats'] > 0 ? (int) round($stats['sold'] / $stats['seats'] * 100) : 0; @endphp
                    <div class="rounded-[22px] border bg-white p-4 shadow-sm" style="border-color: var(--arcs-border);">
                        <div class="flex justify-between text-sm">
                            <span class="font-medium">{{ $cabin }}</span>
                            <span>{{ $stats['sold'] }} / {{ $stats['seats'] }}</span>
                        </div>
                        <div class="mt-2 h-2.5 rounded-full" style="background: #EEF0F4;">
                            <div class="h-2.5 rounded-full" style="width: {{ $pct }}%; background: var(--arcs-accent);"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <script type="module">
            const map = document.getElementById('seat-map');
            if (map && window.ArcsSeatMap) {
                const seats = JSON.parse(map.dataset.seats || '[]');
                const states = JSON.parse(map.dataset.seatStates || '{}');
                window.ArcsSeatMap.draw(map, seats, (seat) => {
                    if (seat.taken) {
                        const status = states[seat.seat_no];
                        return {
                            style: status ? window.ArcsSeatMap.statusToStyle(status) : 'taken',
                            disabled: true,
                        };
                    }

                    return { style: window.ArcsSeatMap.cabinStyle(seat), disabled: true };
                });
            }
        </script>
    @endif

    @if ($tab === 'status')
        <div class="grid gap-4 lg:grid-cols-2">
            <x-panel title="Change gate">
                <form method="POST" action="{{ route('manager.flights.gate', $flight) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PATCH')
                    <div>
                        <x-input-label for="gate" value="Gate" />
                        <input type="text" id="gate" name="gate" value="{{ $flight->gate }}" maxlength="3" pattern="[A-Z][0-9]{1,2}"
                               aria-label="Gate for {{ $flight->flight_no }}" placeholder="—"
                               oninput="this.value = this.value.toUpperCase()"
                               class="arcs-input mt-1 w-24">
                    </div>
                    <x-primary-button>Save gate</x-primary-button>
                </form>
            </x-panel>

            @foreach ($moves as $move)
                @if ($move->value === 'Cancelled')
                    @continue
                @endif
                <x-panel :title="$move->label()">
                    <form method="POST" action="{{ route('manager.flights.status', $flight) }}" class="space-y-3">
                        @csrf
                        @method('PATCH')
                        @if ($move->value === 'Delayed')
                            <div>
                                <x-input-label for="departure_at" value="New departure time" />
                                <input type="datetime-local" id="departure_at" name="departure_at"
                                       aria-label="New departure time for {{ $flight->flight_no }}"
                                       title="Local time at the origin"
                                       class="arcs-input mt-1 block w-full">
                            </div>
                        @endif
                        <button type="submit" name="status" value="{{ $move->value }}" class="arcs-btn-primary">
                            {{ $move->label() }}
                        </button>
                    </form>
                </x-panel>
            @endforeach

            @if (collect($moves)->contains(fn ($m) => $m->value === 'Cancelled'))
                <div class="rounded-[22px] border p-5 lg:col-span-2" style="border-color: #F5C2C0; background: #FCE8E6;">
                    <h2 class="font-semibold" style="color: var(--arcs-error);">Cancel flight</h2>
                    <p class="mt-1 text-sm text-gray-700">This cannot be undone from here.</p>
                    <form method="POST" action="{{ route('manager.flights.status', $flight) }}" class="mt-4">
                        @csrf
                        @method('PATCH')
                        <button type="submit" name="status" value="Cancelled" class="rounded-full px-4 py-2 text-sm font-semibold text-white" style="background: var(--arcs-error);">
                            Cancel flight
                        </button>
                    </form>
                </div>
            @endif

            @if (collect($moves)->isEmpty())
                <x-panel>
                    <p class="text-sm text-gray-600">No status changes are available for this flight.</p>
                </x-panel>
            @endif
        </div>
    @endif
</x-app-layout>
