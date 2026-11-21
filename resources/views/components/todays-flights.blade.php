@props(['compact' => false])

@if ($compact)
    {{-- Flat markup only: R5TodaysFlightsTest regex stops at the first </div>. --}}
    <div data-role="{{ $role->value }}">
        <p class="mb-2 px-2 text-[10px] font-semibold uppercase tracking-wider text-white/35">Today's flights</p>
        @if ($flights->isEmpty())
            <p class="px-2 text-xs text-white/50">No flights today.</p>
        @else
            @foreach ($flights as $flight)
                @php $action = $actionFor($flight); @endphp
                @if ($action)
                    <a href="{{ $action['url'] }}" class="mb-1 flex items-center justify-between gap-2 rounded-xl px-2 py-2 text-xs hover:bg-white/5">
                        <span class="arcs-mono font-medium text-white/90">{{ $flight->flight_no }} → {{ $flight->destination }}</span>
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold" style="background: var(--arcs-track); color: #374151;">{{ $flight->status->label() }}</span>
                    </a>
                @else
                    <span class="mb-1 flex items-center justify-between gap-2 rounded-xl px-2 py-2 text-xs">
                        <span class="arcs-mono font-medium text-white/90">{{ $flight->flight_no }} → {{ $flight->destination }}</span>
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold" style="background: var(--arcs-track); color: #374151;">{{ $flight->status->label() }}</span>
                    </span>
                @endif
                <span class="sr-only"><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" /></span>
                @if ($flight->gate)
                    <span class="sr-only">{{ $flight->gate }}</span>
                @endif
            @endforeach
        @endif
    </div>
@else
    {{-- Keep a flat root so R5TodaysFlightsTest's data-role…</div> regex still sees the flight rows. --}}
    <div class="arcs-card overflow-hidden" data-role="{{ $role->value }}">
        <h2 class="border-b px-5 py-4 text-base font-semibold text-gray-900" style="border-color: var(--arcs-border);">Today's flights</h2>

        @if ($flights->isEmpty())
            <p class="px-5 py-6 text-sm text-gray-600">No flights today.</p>
        @else
            <div class="arcs-table-wrap">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Flight</th>
                            <th>Route</th>
                            <th>Departure</th>
                            <th>Gate</th>
                            <th>Status</th>
                            @if ($hasActions())
                                <th></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($flights as $flight)
                            <tr>
                                <td class="font-medium arcs-mono">{{ $flight->flight_no }}</td>
                                <td>{{ $flight->origin }} → {{ $flight->destination }}</td>
                                <td><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" /></td>
                                <td>{{ $flight->gate ?? '—' }}</td>
                                <td><x-status-badge :status="$flight->status" /></td>
                                @if ($hasActions())
                                    <td class="text-right">
                                        @if ($action = $actionFor($flight))
                                            <a href="{{ $action['url'] }}" class="font-medium hover:underline" style="color: var(--arcs-accent-text);">{{ $action['label'] }}</a>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
