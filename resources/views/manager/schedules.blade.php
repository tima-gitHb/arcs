<x-app-layout>
    <x-page-header title="Schedules" subtitle="Weekly patterns that generate dated flights.">
        <x-slot:breadcrumb>
            <span class="text-gray-400">Flights and aircraft</span>
            <span class="text-gray-300">›</span>
            <span>Schedules</span>
        </x-slot:breadcrumb>
        <a href="{{ route('manager.schedules.create') }}" class="arcs-btn-primary">+ New schedule</a>
    </x-page-header>

    <div class="space-y-6">
        <x-panel>
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Flight</th>
                            <th>Route</th>
                            <th>Days</th>
                            <th>Time</th>
                            <th>Aircraft</th>
                            <th>Validity</th>
                            <th>Active</th>
                            <th>Flights</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($schedules as $schedule)
                            <tr class="cursor-pointer hover:bg-gray-50" onclick="window.location='{{ route('manager.schedules.show', $schedule) }}'">
                                <td class="font-semibold arcs-mono">{{ $schedule->flight_no }}</td>
                                <td>{{ $schedule->origin }} → {{ $schedule->destination }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($schedule->dayLabels() as $day)
                                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" style="background: #EEF0F4;">{{ $day }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="arcs-mono">{{ $schedule->departureHm() }}</td>
                                <td class="arcs-mono">{{ $schedule->aircraft?->registration ?? '—' }}</td>
                                <td class="text-sm">{{ $schedule->valid_from->format('d M Y') }} – {{ $schedule->valid_to->format('d M Y') }}</td>
                                <td>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: {{ $schedule->active ? '#E8F0FE' : '#EEF0F4' }};">
                                        {{ $schedule->active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="arcs-mono">{{ $schedule->flights_count }}</td>
                                <td class="text-right">
                                    <a href="{{ route('manager.schedules.show', $schedule) }}" class="font-medium hover:underline" style="color: var(--arcs-accent-text);" onclick="event.stopPropagation()">Open →</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">
                                    <x-empty-state message="No weekly schedules yet." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>
    </div>
</x-app-layout>
