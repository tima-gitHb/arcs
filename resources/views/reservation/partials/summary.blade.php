<div class="rounded-[14px] border bg-white p-5 sm:p-6 shadow-sm" style="border-color: var(--arcs-border);">
    <p class="text-sm text-gray-500">PNR</p>
    <p class="mt-1 arcs-mono text-3xl font-semibold tracking-[0.25em]" style="color: var(--arcs-accent-text);">
        {{ $reservation->pnr }}
        @if ($reservation->isGroup())
            <span class="ml-2 align-middle rounded-full px-2 py-0.5 font-sans text-xs font-semibold uppercase tracking-wide text-white" style="background: var(--arcs-navy); letter-spacing: 0.06em;">Group</span>
        @endif
        @if ($reservation->isCancelled())
            <span class="ml-2 align-middle rounded-full px-2 py-0.5 font-sans text-xs font-semibold uppercase tracking-wide" style="background: #FCE8E6; color: var(--arcs-error); letter-spacing: 0.06em;">Cancelled</span>
        @endif
    </p>

    <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-gray-500">Flight</dt>
            <dd class="mt-1 font-medium">
                {{ $reservation->flight->flight_no }}
                · {{ $reservation->flight->origin }} → {{ $reservation->flight->destination }}
                · <x-status-badge :status="$reservation->flight->status" />
            </dd>
        </div>
        <div>
            <dt class="text-gray-500">Departure</dt>
            <dd class="mt-1 font-medium">
                <x-local-time :at="$reservation->flight->departure_at" :airport="$reservation->flight->origin" format="d M Y H:i" />
            </dd>
        </div>
    </dl>

    @unless ($reservation->isCancelled())
        <div class="mt-6">
            <a href="{{ route('reservation.refund', $reservation) }}" class="arcs-btn-danger !min-h-0 px-3 py-1.5 text-sm">
                Cancel and refund
            </a>
        </div>
    @endunless

    <h2 class="mt-8 text-base font-semibold text-gray-900">Passengers</h2>

    <div class="mt-3 arcs-table-wrap">
        <table class="arcs-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Seat</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reservation->passengers as $passenger)
                    <tr>
                        <td>
                            <div>{{ $passenger->first_name }} {{ $passenger->last_name }}</div>
                            <a href="{{ route('reservation.edit', $passenger) }}" class="mt-1 inline-block text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">
                                Correct name
                            </a>
                            @if ($passenger->status === App\Enums\PassengerStatus::Booked)
                                <details class="mt-1">
                                    <summary class="inline cursor-pointer text-sm font-medium" style="color: var(--arcs-error);">Cancel</summary>
                                    <form method="POST" action="{{ route('reservation.cancel', $passenger) }}" class="mt-2">
                                        @csrf
                                        <p class="text-sm text-gray-600">Are you sure? Their seat will be freed.</p>
                                        <button type="submit" class="arcs-btn-danger mt-2 !min-h-0 px-3 py-1.5 text-sm">
                                            Yes, cancel
                                        </button>
                                    </form>
                                </details>
                            @endif
                        </td>
                        <td>{{ $passenger->type->label() }}</td>
                        <td><x-status-badge :status="$passenger->status" /></td>
                        <td>
                            @if ($passenger->type === App\Enums\PassengerType::Infant)
                                No seat
                                @if ($passenger->accompanyingAdult)
                                    · with {{ $passenger->accompanyingAdult->first_name }} {{ $passenger->accompanyingAdult->last_name }}
                                @endif
                            @elseif ($passenger->seatAssignment)
                                <span class="font-semibold" style="color: var(--arcs-accent);">{{ $passenger->seatAssignment->seat->seat_no }}</span>
                            @else
                                Not assigned
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
