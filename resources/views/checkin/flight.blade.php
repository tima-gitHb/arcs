@php
    $bookings = $rows->count();
    $passengerTotal = $rows->sum('passengers');
    $checkedInTotal = $rows->sum('checked_in');
    $waiting = max(0, $passengerTotal - $checkedInTotal);
@endphp

<x-app-layout>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 items-start gap-3">
            <a href="{{ route('checkin.home') }}" class="arcs-btn-ghost !min-h-[40px] !px-3" aria-label="Back">←</a>
            <div class="min-w-0">
                <h1 class="text-[26px] font-bold tracking-tight text-gray-900">
                    {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    Departs <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" />
                    · gate {{ $flight->gate ?? '—' }}
                    @if ($flight->checkin_closes_at)
                        · check-in closes <x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="H:i" />
                    @endif
                </p>
            </div>
        </div>
        <x-status-badge :status="$flight->status" />
    </div>

    <p class="mb-6 text-sm text-gray-600">
        Departure <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M H:i" />
        · {{ $flight->status->label() }}
    </p>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="arcs-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Bookings</p>
            <p class="mt-2 text-3xl font-bold" style="color: var(--arcs-accent);">{{ $bookings }}</p>
        </div>
        <div class="arcs-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Passengers</p>
            <p class="mt-2 text-3xl font-bold" style="color: var(--arcs-accent);">{{ $passengerTotal }}</p>
        </div>
        <div class="arcs-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Checked in</p>
            <p class="mt-2 text-3xl font-bold" style="color: var(--arcs-accent);">{{ $checkedInTotal }}</p>
        </div>
        <div class="arcs-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Still to check in</p>
            <p class="mt-2 text-3xl font-bold" style="color: var(--arcs-accent);">{{ $waiting }}</p>
        </div>
    </div>

    <x-panel class="mb-6">
        <form method="GET" action="{{ route('checkin.flights.show', $flight) }}" class="max-w-xl">
            <input type="hidden" name="status" value="{{ $status }}">
            <x-input-label for="q" value="Filter by PNR or name" />
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <x-text-input id="q" name="q" type="text" :value="$filter" class="w-64" autocomplete="off" placeholder="Filter by name or PNR" />
                <button type="submit" class="arcs-btn-primary">Filter</button>
                @if ($filter !== '')
                    <a href="{{ route('checkin.flights.show', ['flight' => $flight, 'status' => $status]) }}" class="text-sm hover:underline" style="color: var(--arcs-accent-text);">Clear</a>
                @endif
            </div>
        </form>

        <div class="mt-4 flex flex-wrap gap-2" data-status-pills>
            @foreach (['all' => 'All', 'waiting' => 'Waiting', 'done' => 'Done'] as $key => $label)
                @php $active = $status === $key; @endphp
                <a href="{{ route('checkin.flights.show', array_filter(['flight' => $flight, 'status' => $key === 'all' ? null : $key, 'q' => $filter !== '' ? $filter : null])) }}"
                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm {{ $active ? 'font-semibold text-white' : 'text-gray-600' }}"
                    style="{{ $active ? 'background: var(--arcs-bar)' : 'background: #EEF0F4' }}">
                    {{ $label }}
                    <span class="arcs-mono text-xs opacity-70">{{ $statusCounts[$key] }}</span>
                </a>
            @endforeach
        </div>
    </x-panel>

    @php
        $emptyMessage = $filter !== ''
            ? 'No reservations match “'.$filter.'”.'
            : ($status === 'waiting'
                ? 'No bookings still waiting to check in.'
                : ($status === 'done' ? 'No bookings fully checked in yet.' : 'No reservations on this flight.'));
    @endphp

    @if ($rows->isEmpty())
        <x-empty-state :message="$emptyMessage" />
    @else
        <x-panel>
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>PNR</th>
                            <th>Lead passenger</th>
                            <th>Passengers</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="arcs-mono font-semibold tracking-[0.15em]" style="color: var(--arcs-accent-text);">{{ $row['reservation']->pnr }}</td>
                                <td class="font-medium">{{ $row['lead'] }}</td>
                                <td class="text-gray-700">
                                    {{ $row['passengers'] }} passenger{{ $row['passengers'] === 1 ? '' : 's' }} · {{ $row['checked_in'] }} checked in
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('checkin.reservations.group', $row['reservation']) }}" class="arcs-btn-primary text-sm">
                                        Open
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-panel>
    @endif
</x-app-layout>
