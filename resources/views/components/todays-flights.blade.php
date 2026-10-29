<div class="bg-white rounded-lg shadow-sm overflow-x-auto" data-role="{{ $role->value }}">
    <h2 class="px-4 pt-4 text-lg font-semibold text-navy">Today's flights</h2>

    @if ($flights->isEmpty())
        <p class="px-4 py-4 text-sm text-gray-600">No flights today.</p>
    @else
        <table class="mt-2 w-full text-sm">
            <thead class="text-left text-gray-500 border-b border-gray-200">
                <tr>
                    <th class="px-4 py-2 font-medium">Flight</th>
                    <th class="px-4 py-2 font-medium">Route</th>
                    <th class="px-4 py-2 font-medium">Departure</th>
                    <th class="px-4 py-2 font-medium">Gate</th>
                    <th class="px-4 py-2 font-medium">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($flights as $flight)
                    <tr class="border-b border-gray-100 last:border-0">
                        <td class="px-4 py-2 font-medium">{{ $flight->flight_no }}</td>
                        <td class="px-4 py-2">{{ $flight->origin }} → {{ $flight->destination }}</td>
                        <td class="px-4 py-2">{{ $flight->departure_at->format('H:i') }}</td>
                        <td class="px-4 py-2">{{ $flight->gate ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $flight->status->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
