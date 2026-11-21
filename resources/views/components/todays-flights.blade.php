{{-- Keep a flat root so R5TodaysFlightsTest's data-role…</div> regex still sees the flight rows. --}}
<div class="overflow-hidden rounded-[14px] border bg-white shadow-sm" style="border-color: var(--arcs-border);" data-role="{{ $role->value }}">
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
