{{-- Baggage list (SR-2). Needs $bags (with passenger), $bagCount and $totalWeight (a string with one decimal). --}}
<div class="print-card bg-white rounded-lg shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500 border-b border-gray-200">
            <tr>
                <th class="px-4 py-2 font-medium">Tag</th>
                <th class="px-4 py-2 font-medium">Passenger</th>
                <th class="px-4 py-2 font-medium">Type</th>
                <th class="px-4 py-2 font-medium">Weight (kg)</th>
                <th class="px-4 py-2 font-medium">Handling</th>
                <th class="px-4 py-2 font-medium">Offloaded</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($bags as $bag)
                {{-- data-bag and data-offloaded: markers for the tests, like data-figure on the dashboard. --}}
                <tr data-bag="{{ $bag->tag_no }}" data-offloaded="{{ $bag->offloaded_at ? 'yes' : 'no' }}" class="border-b border-gray-100 last:border-0">
                    <td class="px-4 py-2 font-mono">{{ $bag->tag_no }}</td>
                    <td class="px-4 py-2">{{ $bag->passenger->first_name }} {{ $bag->passenger->last_name }}</td>
                    <td class="px-4 py-2">{{ $bag->type->label() }}</td>
                    <td class="px-4 py-2">{{ $bag->weight_kg }}</td>
                    <td class="px-4 py-2">{{ $bag->handling->label() }}</td>
                    <td class="px-4 py-2">{{ $bag->offloaded_at ? 'Yes' : 'No' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-gray-500">No bags on this flight.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p class="border-t border-gray-200 px-4 py-3 text-sm font-medium text-navy">Total: {{ $bagCount }} {{ Str::plural('bag', $bagCount) }}, {{ $totalWeight }} kg</p>
</div>
