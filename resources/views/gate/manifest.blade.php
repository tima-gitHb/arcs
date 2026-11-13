<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h1 class="text-2xl font-semibold text-navy">
                {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}
            </h1>
            <a href="{{ route('gate.home') }}" class="text-sm font-medium text-navy hover:underline">← Today's flights</a>
        </div>
    </x-slot>

    <div class="mx-auto grid max-w-7xl gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
    <div class="space-y-6">
        {{-- Header: flight and live counts (SR-11). Counts refresh every 10 s. --}}
        <div class="rounded-lg bg-white p-6 shadow-sm" id="manifest-header" data-counts-url="{{ route('gate.flights.counts', $flight) }}">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <span>Departure <span class="font-semibold"><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M H:i" /></span></span>
                <span class="text-lg font-semibold text-navy">Gate {{ $flight->gate ?? 'TBA' }}</span>
                <span class="rounded-full bg-gray-100 px-3 py-0.5 font-medium text-gray-800" data-flight-status>{{ $flight->status->label() }}</span>
            </div>

            <dl class="mt-4 grid grid-cols-3 gap-4 text-center">
                <div class="rounded bg-sky-50 p-3">
                    <dt class="text-xs uppercase text-gray-500">Checked in</dt>
                    <dd class="text-3xl font-bold text-navy" data-count="checked_in">{{ $counts['checked_in'] }}</dd>
                </div>
                <div class="rounded bg-green-50 p-3">
                    <dt class="text-xs uppercase text-gray-500">Boarded</dt>
                    <dd class="text-3xl font-bold text-green-800" data-count="boarded">{{ $counts['boarded'] }}</dd>
                </div>
                <div class="rounded bg-amber-50 p-3">
                    <dt class="text-xs uppercase text-gray-500">Missing</dt>
                    <dd class="text-3xl font-bold text-amber-800" data-count="missing">{{ $counts['missing'] }}</dd>
                </div>
            </dl>
        </div>

        @if (session('boardingPassUrl'))
            <a href="{{ session('boardingPassUrl') }}" target="_blank"
               class="inline-block rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">
                Print boarding pass
            </a>
        @endif

        @include('gate.partials.boarding-panel')

        @include('gate.partials.close-boarding')

        {{-- Passenger list (SR-45): who checked in / boarded each passenger, from the audit log helpers. --}}
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Passenger</th>
                        <th class="px-4 py-2 font-medium">Seat</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Checked in / boarded</th>
                        <th class="px-4 py-2 font-medium">Services · at the door</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($passengers as $passenger)
                        <tr class="border-b border-gray-100 align-top last:border-0" data-passenger="{{ $passenger->id }}">
                            <td class="px-4 py-2">
                                <span class="font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</span>
                                <span class="block text-xs text-gray-500">
                                    {{ $passenger->type->value }} · PNR {{ $passenger->reservation->pnr }}
                                    @if ($passenger->accompanyingAdult)
                                        · with {{ $passenger->accompanyingAdult->first_name }}
                                    @endif
                                </span>
                            </td>
                            <td class="px-4 py-2 font-mono">{{ $passenger->seatAssignment?->seat->seat_no ?? '—' }}</td>
                            <td class="px-4 py-2">
                                <span @class([
                                    'rounded px-2 py-0.5 text-xs font-medium',
                                    'bg-green-100 text-green-800' => $passenger->status === \App\Enums\PassengerStatus::Boarded,
                                    'bg-amber-100 text-amber-800' => in_array($passenger->status, [\App\Enums\PassengerStatus::CheckedIn, \App\Enums\PassengerStatus::NoShow], true),
                                    'bg-gray-100 text-gray-700' => $passenger->status === \App\Enums\PassengerStatus::Booked,
                                ])>{{ $passenger->status->label() }}</span>
                                @if ($passenger->status === \App\Enums\PassengerStatus::Booked && $flight->isLateCheckInOpen())
                                    <a href="{{ route('gate.passengers.late-checkin', $passenger) }}" class="mt-1 block text-xs font-medium text-navy hover:underline">Late check-in</a>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-700">
                                @if ($passenger->checkedInBy)
                                    <span class="block">Checked in by {{ $passenger->checkedInBy->user->name }} at <x-station-time :at="$passenger->checkedInBy->changed_at" format="H:i" /></span>
                                @endif
                                @if ($passenger->boardedBy)
                                    <span class="block">Boarded by {{ $passenger->boardedBy->user->name }} at <x-station-time :at="$passenger->boardedBy->changed_at" format="H:i" /></span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-xs">
                                @foreach ($passenger->specialServices as $service)
                                    <span class="mr-1 inline-block rounded bg-indigo-50 px-1.5 py-0.5 font-medium text-indigo-800" title="{{ $service->type->label() }}">{{ $service->type->value }}</span>
                                @endforeach
                                @foreach ($passenger->bags as $bag)
                                    <span class="mt-1 block text-gray-700">{{ $bag->type->label() }} · <span class="font-mono">{{ $bag->tag_no }}</span></span>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-4 text-gray-600">No passengers on this flight.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-seat-map
        mode="gate"
        hint="Live seat states. Updates every 10 seconds."
        data-seats="{{ $seatMap->toJson() }}"
        data-seat-states="{{ json_encode($seatStates) }}"
    />
    </div>

    <script type="module">
        // Refresh the counts and seat colours every 10 seconds without reloading (SR-11).
        const header = document.getElementById('manifest-header');
        const map = document.getElementById('seat-map');
        const seats = JSON.parse(map.dataset.seats);
        const initialStates = JSON.parse(map.dataset.seatStates || '{}');

        window.ArcsSeatMap.draw(map, seats, (seat) => {
            const status = initialStates[seat.seat_no];
            const style = status
                ? window.ArcsSeatMap.statusToStyle(status)
                : window.ArcsSeatMap.cabinStyle({ ...seat, taken: false });

            return { style, disabled: true };
        });

        async function refreshCounts() {
            try {
                const response = await fetch(header.dataset.countsUrl, { headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    return;
                }
                const counts = await response.json();
                ['checked_in', 'boarded', 'missing'].forEach((key) => {
                    header.querySelector(`[data-count="${key}"]`).textContent = counts[key];
                });
                if (counts.seats) {
                    window.ArcsSeatMap.applySeatStates(map, counts.seats);
                }
            } catch {
                // Network hiccup: try again at the next tick.
            }
        }

        setInterval(refreshCounts, 10000);
    </script>
</x-app-layout>
