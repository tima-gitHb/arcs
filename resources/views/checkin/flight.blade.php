<x-app-layout>
    <x-page-header :title="$flight->flight_no.' · '.$flight->origin.' → '.$flight->destination" subtitle="Group check-in by reservation">
        <x-status-badge :status="$flight->status" />
        <a href="{{ route('checkin.home') }}" class="arcs-btn-secondary">← Today's flights</a>
    </x-page-header>

    <p class="mb-6 text-sm text-gray-600">
        Departure <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M H:i" />
        · {{ $flight->status->label() }}
    </p>

    <x-panel class="mb-6">
        <form method="GET" action="{{ route('checkin.flights.show', $flight) }}" class="max-w-xl">
            <x-input-label for="q" value="Filter by PNR or name" />
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <x-text-input id="q" name="q" type="text" :value="$filter" class="w-64" autocomplete="off" placeholder="PNR or name" />
                <button type="submit" class="arcs-btn-primary">Filter</button>
                @if ($filter !== '')
                    <a href="{{ route('checkin.flights.show', $flight) }}" class="text-sm hover:underline" style="color: var(--arcs-accent-text);">Clear</a>
                @endif
            </div>
        </form>
    </x-panel>

    @php
        $emptyMessage = $filter !== ''
            ? 'No reservations match “'.$filter.'”.'
            : 'No reservations on this flight.';
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
