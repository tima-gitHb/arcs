<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-navy">
                    {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    Departure <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M H:i" />
                    · {{ $flight->status->label() }}
                </p>
            </div>
            <a href="{{ route('checkin.home') }}" class="text-sm font-medium text-navy hover:underline">← Today's flights</a>
        </div>
    </x-slot>

    <form method="GET" action="{{ route('checkin.flights.show', $flight) }}" class="mb-6 max-w-xl rounded-lg bg-white p-4 shadow-sm">
        <x-input-label for="q" value="Filter by PNR or name" />
        <div class="mt-1 flex flex-wrap items-center gap-3">
            <x-text-input id="q" name="q" type="text" :value="$filter" class="w-64" autocomplete="off" placeholder="PNR or name" />
            <button type="submit" class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">Filter</button>
            @if ($filter !== '')
                <a href="{{ route('checkin.flights.show', $flight) }}" class="text-sm text-gray-600 hover:underline">Clear</a>
            @endif
        </div>
    </form>

    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">PNR</th>
                    <th class="px-4 py-2 font-medium">Lead passenger</th>
                    <th class="px-4 py-2 font-medium">Passengers</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $row)
                    <tr>
                        <td class="px-4 py-3 font-mono font-semibold tracking-[0.15em] text-navy">{{ $row['reservation']->pnr }}</td>
                        <td class="px-4 py-3 font-medium">{{ $row['lead'] }}</td>
                        <td class="px-4 py-3 text-gray-700">
                            {{ $row['passengers'] }} passenger{{ $row['passengers'] === 1 ? '' : 's' }} · {{ $row['checked_in'] }} checked in
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('checkin.reservations.group', $row['reservation']) }}"
                               class="rounded bg-navy px-3 py-1.5 text-sm font-medium text-white hover:bg-navy/90">
                                Open
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-gray-600">
                            @if ($filter !== '')
                                No reservations match “{{ $filter }}”.
                            @else
                                No reservations on this flight.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
