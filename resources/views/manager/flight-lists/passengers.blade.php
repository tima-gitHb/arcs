{{-- Passenger list (SR-2, SR-45). Needs $passengers, loaded with reservation, seatAssignment.seat, checkedInBy.user, boardedBy.user. --}}
<div class="print-card overflow-hidden rounded-[14px] border bg-white shadow-sm" style="border-color: var(--arcs-border);">
    <div class="arcs-table-wrap">
        <table class="arcs-table">
            <thead>
                <tr>
                    <th>Passenger</th>
                    <th>PNR</th>
                    <th>Seat</th>
                    <th>Status</th>
                    <th>Checked in by</th>
                    <th>Boarded by</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($passengers as $passenger)
                    <tr class="align-top">
                        <td class="font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</td>
                        <td class="arcs-mono tracking-wider">{{ $passenger->reservation->pnr }}</td>
                        <td>{{ $passenger->seatAssignment?->seat->seat_no ?? '—' }}</td>
                        <td><x-status-badge :status="$passenger->status" /></td>
                        {{-- Who did it: the audit_log row (SR-45), or nothing if it never happened. --}}
                        <td>
                            @if ($passenger->checkedInBy)
                                {{ $passenger->checkedInBy->user->name }}
                                <span class="block text-xs text-gray-500"><x-station-time :at="$passenger->checkedInBy->changed_at" format="d M Y H:i" /></span>
                            @else
                                —
                            @endif
                        </td>
                        <td>
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
                        <td colspan="6" class="text-center text-gray-500">No passengers on this flight.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
