{{-- One printable boarding pass card. Expects $passenger, $pass, $flight, $boardingTime, $barcodeSvg. --}}
<div class="overflow-hidden rounded-xl border-2 border-navy bg-white">
    <div class="flex items-center justify-between bg-navy px-6 py-3 text-white">
        <span class="text-lg font-semibold tracking-wide">ARCS</span>
        <span class="text-sm uppercase tracking-[0.2em]">Boarding pass</span>
    </div>

    <div class="grid grid-cols-2 gap-x-6 gap-y-4 p-6 text-sm sm:grid-cols-3">
        <div class="col-span-2 sm:col-span-3">
            <p class="text-xs uppercase text-gray-500">Passenger</p>
            <p class="text-xl font-semibold">{{ strtoupper($passenger->last_name) }} / {{ strtoupper($passenger->first_name) }}</p>
        </div>

        <div>
            <p class="text-xs uppercase text-gray-500">Flight</p>
            <p class="text-lg font-semibold">{{ $flight->flight_no }}</p>
        </div>
        <div>
            <p class="text-xs uppercase text-gray-500">From → To</p>
            <p class="text-lg font-semibold">{{ $flight->origin }} → {{ $flight->destination }}</p>
        </div>
        <div>
            <p class="text-xs uppercase text-gray-500">Date</p>
            <p class="text-lg font-semibold">{{ $flight->departure_at->format('d M Y') }}</p>
        </div>

        <div>
            <p class="text-xs uppercase text-gray-500">Gate</p>
            <p class="text-2xl font-bold text-navy">{{ $flight->gate ?? 'TBA' }}</p>
        </div>
        <div>
            <p class="text-xs uppercase text-gray-500">Boarding</p>
            <p class="text-2xl font-bold text-navy">{{ $boardingTime->format('H:i') }}</p>
        </div>
        <div>
            <p class="text-xs uppercase text-gray-500">Departure</p>
            <p class="text-lg font-semibold">{{ $flight->departure_at->format('H:i') }}</p>
        </div>

        <div>
            <p class="text-xs uppercase text-gray-500">Seat</p>
            @if ($passenger->type === \App\Enums\PassengerType::Infant)
                <p class="text-lg font-semibold">Infant</p>
                @if ($passenger->accompanyingAdult)
                    <p class="text-xs text-gray-600">on the lap of {{ $passenger->accompanyingAdult->first_name }} {{ $passenger->accompanyingAdult->last_name }}</p>
                @endif
            @else
                <p class="text-2xl font-bold text-navy">{{ $passenger->seatAssignment?->seat->seat_no ?? '—' }}</p>
            @endif
        </div>
        <div>
            <p class="text-xs uppercase text-gray-500">PNR</p>
            <p class="font-mono text-lg font-semibold tracking-[0.2em]">{{ $passenger->reservation->pnr }}</p>
        </div>
        <div>
            <p class="text-xs uppercase text-gray-500">Issued</p>
            <p>{{ $pass->issued_at->format('d M H:i') }}</p>
        </div>
    </div>

    <div class="border-t border-dashed border-gray-300 px-6 py-4 text-center" data-barcode="{{ $pass->barcode }}">
        <div class="inline-block">{!! $barcodeSvg !!}</div>
        <p class="mt-1 font-mono text-xs text-gray-600">{{ $pass->barcode }}</p>
    </div>
</div>
