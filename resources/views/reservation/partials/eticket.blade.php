@php
    use App\Services\AllowanceService;

    $allowances = app(AllowanceService::class);
@endphp

<div class="eticket print:shadow-none">
    <div class="border-b border-navy/10 pb-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-navy/70">ARCS e-ticket / receipt</p>
                <p class="mt-2 text-3xl font-semibold tracking-[0.25em] text-navy">{{ $reservation->pnr }}</p>
                <p class="mt-2 text-sm text-gray-500">Give this PNR to the passenger for check-in.</p>
            </div>
            @if ($payment)
                <x-receipt :payment="$payment" />
            @endif
        </div>
    </div>

    <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-gray-500">Flight</dt>
            <dd class="mt-1 text-base font-semibold text-navy">
                {{ $reservation->flight->flight_no }}
                <span class="font-medium text-gray-700">· {{ $reservation->flight->origin }} → {{ $reservation->flight->destination }}</span>
            </dd>
        </div>
        <div>
            <dt class="text-gray-500">Departure (Beirut time)</dt>
            <dd class="mt-1 font-medium">
                {{ $reservation->flight->departure_at->timezone(config('app.timezone'))->format('D d M Y · H:i') }}
            </dd>
        </div>
        @if ($reservation->contact_email)
            <div>
                <dt class="text-gray-500">Contact email</dt>
                <dd class="mt-1 font-medium">{{ $reservation->contact_email }}</dd>
            </div>
        @endif
        @if ($reservation->contact_phone)
            <div>
                <dt class="text-gray-500">Contact phone</dt>
                <dd class="mt-1 font-medium">{{ $reservation->contact_phone }}</dd>
            </div>
        @endif
    </dl>

    <h2 class="mt-8 text-lg font-semibold text-navy">Passengers</h2>

    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-gray-500">
                    <th class="py-2 font-medium">Name</th>
                    <th class="py-2 font-medium">Type</th>
                    <th class="py-2 font-medium">Status</th>
                    <th class="py-2 font-medium">Class</th>
                    <th class="py-2 font-medium">Seat</th>
                    <th class="py-2 font-medium">Baggage allowance</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reservation->passengers as $passenger)
                    @php
                        $allowance = $allowances->for($passenger);
                    @endphp
                    <tr class="border-b border-gray-100">
                        <td class="py-3 font-medium text-gray-900">
                            <div>{{ $passenger->first_name }} {{ $passenger->last_name }}</div>
                            <div class="print:hidden">
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
                            </div>
                        </td>
                        <td class="py-3">{{ $passenger->type->label() }}</td>
                        <td class="py-3">{{ $passenger->status->label() }}</td>
                        <td class="py-3">{{ $passenger->cabin_class?->label() ?? '—' }}</td>
                        <td class="py-3">
                            @if ($passenger->type === App\Enums\PassengerType::Infant)
                                No seat
                            @elseif ($passenger->seatAssignment)
                                {{ $passenger->seatAssignment->seat->seat_no }}
                            @else
                                Not assigned
                            @endif
                        </td>
                        <td class="py-3">
                            @if ($allowance->isSet)
                                <span class="font-medium">{{ $allowance->cabinClass->label() }}</span>
                                {{ $allowance->pieces }} × {{ rtrim(rtrim(number_format($allowance->kgPerBag, 1, '.', ''), '0'), '.') }} kg
                                <span class="block text-xs text-gray-500">
                                    {{ rtrim(rtrim(number_format($allowance->totalKg, 1, '.', ''), '0'), '.') }} kg total
                                    @if ($passenger->extra_bags > 0 || $passenger->extra_kg > 0)
                                        · includes
                                        @if ($passenger->extra_bags > 0)
                                            +{{ $passenger->extra_bags }} bag{{ $passenger->extra_bags === 1 ? '' : 's' }}
                                        @endif
                                        @if ($passenger->extra_bags > 0 && $passenger->extra_kg > 0) ,@endif
                                        @if ($passenger->extra_kg > 0)
                                            +{{ $passenger->extra_kg }} kg
                                        @endif
                                    @endif
                                </span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
