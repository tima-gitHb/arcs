{{-- Special service list (SR-2). Needs $services (with passenger). --}}
<div class="print-card overflow-hidden rounded-[14px] border bg-white shadow-sm" style="border-color: var(--arcs-border);">
    <div class="arcs-table-wrap">
        <table class="arcs-table">
            <thead>
                <tr>
                    <th>Passenger</th>
                    <th>Code</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($services as $service)
                    <tr class="align-top">
                        <td>{{ $service->passenger->first_name }} {{ $service->passenger->last_name }}</td>
                        <td class="arcs-mono" title="{{ $service->type->label() }}">{{ $service->type->value }}</td>
                        <td>{{ $service->details ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-gray-500">No special services on this flight.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
