<div class="rounded-lg bg-white p-6 shadow-sm">
    <p class="text-sm text-gray-500">PNR</p>
    <p class="mt-1 text-3xl font-semibold tracking-[0.25em] text-navy">{{ $reservation->pnr }}</p>

    <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-gray-500">Flight</dt>
            <dd class="mt-1 font-medium">
                {{ $reservation->flight->flight_no }}
                · {{ $reservation->flight->origin }} → {{ $reservation->flight->destination }}
                · {{ $reservation->flight->status->label() }}
            </dd>
        </div>
        <div>
            <dt class="text-gray-500">Departure</dt>
            <dd class="mt-1 font-medium">
                <x-local-time :at="$reservation->flight->departure_at" :airport="$reservation->flight->origin" format="d M Y H:i" />
            </dd>
        </div>
    </dl>

    <h2 class="mt-8 text-lg font-semibold text-navy">Passengers</h2>

    <table class="mt-3 w-full text-left text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-gray-500">
                <th class="py-2 font-medium">Name</th>
                <th class="py-2 font-medium">Type</th>
                <th class="py-2 font-medium">Status</th>
                <th class="py-2 font-medium">Seat</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($reservation->passengers as $passenger)
                <tr class="border-b border-gray-100">
                    <td class="py-3">
                        <div>{{ $passenger->first_name }} {{ $passenger->last_name }}</div>
                        <a href="{{ route('reservation.edit', $passenger) }}" class="mt-1 inline-block text-sm font-medium text-navy hover:underline">
                            Correct name
                        </a>
                        @if ($passenger->status === App\Enums\PassengerStatus::Booked)
                            <details class="mt-1">
                                <summary class="inline cursor-pointer text-sm font-medium text-red-700">Cancel</summary>
                                <form method="POST" action="{{ route('reservation.cancel', $passenger) }}" class="mt-2">
                                    @csrf
                                    <p class="text-sm text-gray-600">Are you sure? Their seat will be freed.</p>
                                    <button type="submit" class="mt-2 rounded bg-red-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-800">
                                        Yes, cancel
                                    </button>
                                </form>
                            </details>
                        @endif
                    </td>
                    <td class="py-3">{{ $passenger->type->label() }}</td>
                    <td class="py-3">{{ $passenger->status->label() }}</td>
                    <td class="py-3">
                        @if ($passenger->type === App\Enums\PassengerType::Infant)
                            No seat
                            @if ($passenger->accompanyingAdult)
                                · with {{ $passenger->accompanyingAdult->first_name }} {{ $passenger->accompanyingAdult->last_name }}
                            @endif
                        @elseif ($passenger->seatAssignment)
                            {{ $passenger->seatAssignment->seat->seat_no }}
                        @else
                            Not assigned
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
