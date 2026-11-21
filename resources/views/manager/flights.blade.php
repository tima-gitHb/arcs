<x-app-layout>
    <x-page-header title="Flights" subtitle="Search and open a flight to change status, gate or load.">
        <x-slot:breadcrumb>
            <span class="text-gray-400">Flights and aircraft</span>
            <span class="text-gray-300">›</span>
            <span>Flights</span>
        </x-slot:breadcrumb>
        <a href="{{ route('manager.flights.create') }}" class="arcs-btn-primary">+ New flight</a>
    </x-page-header>

    <div class="space-y-6">
        <x-panel>
            <form method="GET" action="{{ route('manager.flights') }}" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[12rem] flex-1">
                    <x-input-label for="q" value="Search" />
                    <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" :value="$filters['q']" placeholder="Flight no. or route" />
                </div>
                <div>
                    <x-input-label for="date" value="Date" />
                    <input type="date" id="date" name="date" value="{{ $filters['date'] }}" class="arcs-input mt-1 block w-44">
                </div>
                <x-primary-button>Filter</x-primary-button>
            </form>

            <div class="mt-4 flex flex-wrap gap-2">
                @php
                    $pills = ['' => 'All'];
                    foreach (\App\Enums\FlightStatus::cases() as $status) {
                        $pills[$status->value] = $status->label();
                    }
                @endphp
                @foreach ($pills as $value => $label)
                    @php
                        $count = $value === '' ? $statusCounts->sum() : (int) ($statusCounts[$value] ?? 0);
                        $active = $filters['status'] === $value;
                    @endphp
                    <a href="{{ route('manager.flights', array_filter(['q' => $filters['q'] ?: null, 'date' => $filters['date'] ?: null, 'status' => $value ?: null])) }}"
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm {{ $active ? 'font-semibold text-white' : 'text-gray-600' }}"
                        style="{{ $active ? 'background: var(--arcs-bar)' : 'background: #EEF0F4' }}">
                        {{ $label }}
                        <span class="arcs-mono text-xs opacity-70">{{ $count }}</span>
                    </a>
                @endforeach
            </div>
        </x-panel>

        <x-panel>
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Flight</th>
                            <th>Route</th>
                            <th>Departure</th>
                            <th>Gate</th>
                            <th>Aircraft</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($flights as $flight)
                            <tr class="cursor-pointer hover:bg-gray-50" onclick="window.location='{{ route('manager.flights.show', $flight) }}'">
                                <td class="font-semibold arcs-mono">{{ $flight->flight_no }}</td>
                                <td>{{ $flight->origin }} → {{ $flight->destination }}</td>
                                <td><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M Y H:i" /></td>
                                <td>{{ $flight->gate ?? '—' }}</td>
                                <td class="arcs-mono">{{ $flight->aircraft?->registration ?? '—' }}</td>
                                <td><x-status-badge :status="$flight->status" /></td>
                                <td class="text-right">
                                    <a href="{{ route('manager.flights.show', $flight) }}" class="font-medium hover:underline" style="color: var(--arcs-accent-text);" onclick="event.stopPropagation()">Open →</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <x-empty-state message="No flights match these filters." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $flights->links() }}</div>
        </x-panel>
    </div>
</x-app-layout>
