<x-app-layout>
    <x-page-header title="Aircraft fleet" subtitle="Registration, layout and the next departure for each aircraft.">
        <x-slot:breadcrumb>
            <span class="text-gray-400">Flights and aircraft</span>
            <span class="text-gray-300">›</span>
            <span>Aircraft fleet</span>
        </x-slot:breadcrumb>
        <a href="{{ route('manager.aircraft.create') }}" class="arcs-btn-primary">+ New aircraft</a>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($fleet as $row)
            @php $plane = $row['aircraft']; @endphp
            <div class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-lg font-bold arcs-mono">{{ $plane->registration }}</p>
                        <p class="text-sm text-gray-600">{{ $plane->model }}</p>
                    </div>
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: {{ $row['in_use'] ? '#E8F0FE' : '#EEF0F4' }};">
                        {{ $row['in_use'] ? 'In service' : 'Idle' }}
                    </span>
                </div>
                <p class="mt-3 text-sm text-gray-700">
                    {{ $row['cabins']['Business'] ?? 0 }} Business ·
                    {{ $row['cabins']['Premium'] ?? 0 }} Premium ·
                    {{ $row['cabins']['Economy'] ?? 0 }} Economy
                </p>
                <p class="mt-1 text-xs text-gray-500">{{ $plane->seats_count }} seats</p>
                <p class="mt-3 text-sm text-gray-600">
                    Next:
                    @if ($row['next'])
                        <span class="arcs-mono font-medium text-gray-900">{{ $row['next']->flight_no }}</span>
                        <x-local-time :at="$row['next']->departure_at" :airport="$row['next']->origin" format="d M H:i" />
                    @else
                        —
                    @endif
                </p>
            </div>
        @empty
            <div class="sm:col-span-2 xl:col-span-3">
                <x-empty-state message="No aircraft yet." />
            </div>
        @endforelse
    </div>
</x-app-layout>
