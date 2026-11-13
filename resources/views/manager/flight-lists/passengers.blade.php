{{-- Passenger list (SR-2, SR-45). Needs $passengers, loaded with reservation, seatAssignment.seat, checkedInBy.user, boardedBy.user. --}}
<div class="print-card bg-white rounded-lg shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500 border-b border-gray-200">
            <tr>
                <th class="px-4 py-2 font-medium">Passenger</th>
                <th class="px-4 py-2 font-medium">PNR</th>
                <th class="px-4 py-2 font-medium">Seat</th>
                <th class="px-4 py-2 font-medium">Status</th>
                <th class="px-4 py-2 font-medium">Checked in by</th>
                <th class="px-4 py-2 font-medium">Boarded by</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($passengers as $passenger)
                <tr class="border-b border-gray-100 last:border-0 align-top">
                    <td class="px-4 py-2 font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</td>
                    <td class="px-4 py-2 font-mono tracking-wider">{{ $passenger->reservation->pnr }}</td>
                    <td class="px-4 py-2">{{ $passenger->seatAssignment?->seat->seat_no ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $passenger->status->label() }}</td>
                    {{-- Who did it: the audit_log row (SR-45), or nothing if it never happened. --}}
                    <td class="px-4 py-2">
                        @if ($passenger->checkedInBy)
                            {{ $passenger->checkedInBy->user->name }}
                            <span class="block text-xs text-gray-500"><x-station-time :at="$passenger->checkedInBy->changed_at" format="d M Y H:i" /></span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-2">
                        @if ($passenger->boardedBy)
                            {{ $passenger->boardedBy->user->name }}
                            <span class="block text-xs text-gray-500"><x-station-time :at="$passenger->boardedBy->changed_at" format="d M Y H:i" /></span>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-gray-500">No passengers on this flight.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
