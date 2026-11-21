{{-- Baggage list (SR-2). Needs $bags (with passenger), $bagCount and $totalWeight (a string with one decimal). --}}
<div class="print-card overflow-hidden rounded-[14px] border bg-white shadow-sm" style="border-color: var(--arcs-border);">
    <div class="arcs-table-wrap">
        <table class="arcs-table">
            <thead>
                <tr>
                    <th>Tag</th>
                    <th>Passenger</th>
                    <th>Type</th>
                    <th>Weight (kg)</th>
                    <th>Handling</th>
                    <th>Offloaded</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($bags as $bag)
                    {{-- data-bag and data-offloaded: markers for the tests, like data-figure on the dashboard. --}}
                    <tr data-bag="{{ $bag->tag_no }}" data-offloaded="{{ $bag->offloaded_at ? 'yes' : 'no' }}">
                        <td class="arcs-mono">{{ $bag->tag_no }}</td>
                        <td>{{ $bag->passenger->first_name }} {{ $bag->passenger->last_name }}</td>
                        <td>{{ $bag->type->label() }}</td>
                        <td>{{ $bag->weight_kg }}</td>
                        <td>{{ $bag->handling->label() }}</td>
                        <td>{{ $bag->offloaded_at ? 'Yes' : 'No' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-gray-500">No bags on this flight.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="border-t px-4 py-3 text-sm font-medium text-gray-900" style="border-color: var(--arcs-border);">Total: {{ $bagCount }} {{ Str::plural('bag', $bagCount) }}, {{ $totalWeight }} kg</p>
</div>
