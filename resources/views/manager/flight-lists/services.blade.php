{{-- Special service list (SR-2). Needs $services (with passenger). --}}
<div class="print-card bg-white rounded-lg shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500 border-b border-gray-200">
            <tr>
                <th class="px-4 py-2 font-medium">Passenger</th>
                <th class="px-4 py-2 font-medium">Code</th>
                <th class="px-4 py-2 font-medium">Details</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($services as $service)
                <tr class="border-b border-gray-100 last:border-0 align-top">
                    <td class="px-4 py-2">{{ $service->passenger->first_name }} {{ $service->passenger->last_name }}</td>
                    <td class="px-4 py-2 font-mono" title="{{ $service->type->label() }}">{{ $service->type->value }}</td>
                    <td class="px-4 py-2">{{ $service->details ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="px-4 py-6 text-center text-gray-500">No special services on this flight.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
