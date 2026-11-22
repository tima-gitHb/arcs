{{-- One printable boarding pass card. Expects $passenger, $pass, $flight, $boardingTime, $barcodeSvg. --}}
<div class="overflow-hidden rounded-[14px] border-2 bg-white" style="border-color: var(--arcs-bar);">
    <div class="flex items-center justify-between px-6 py-3 text-white" style="background: var(--arcs-bar);">
        <span class="text-lg font-semibold tracking-wide">{{ config('arcs.airline.name') }}</span>
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
            <p class="text-lg font-semibold"><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M Y" /></p>
        </div>

        <div>
            <p class="text-xs uppercase text-gray-500">Gate</p>
            <p class="text-2xl font-bold" style="color: var(--arcs-bar);">{{ $flight->gate ?? 'TBA' }}</p>
        </div>
        <div>
            <p class="text-xs uppercase text-gray-500">Boarding</p>
            <p class="text-2xl font-bold" style="color: var(--arcs-bar);"><x-local-time :at="$boardingTime" :airport="$flight->origin" format="H:i" /></p>
        </div>
        <div>
            <p class="text-xs uppercase text-gray-500">Departure</p>
            <p class="text-lg font-semibold"><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" /></p>
        </div>

        <div>
            <p class="text-xs uppercase text-gray-500">Seat</p>
            @if ($passenger->type === \App\Enums\PassengerType::Infant)
                <p class="text-lg font-semibold">Infant</p>
                @if ($passenger->accompanyingAdult)
                    <p class="text-xs text-gray-600">on the lap of {{ $passenger->accompanyingAdult->first_name }} {{ $passenger->accompanyingAdult->last_name }}</p>
                @endif
            @else
                <p class="text-2xl font-bold" style="color: var(--arcs-bar);">{{ $passenger->seatAssignment?->seat->seat_no ?? '—' }}</p>
            @endif
        </div>
        <div>
            <p class="text-xs uppercase text-gray-500">PNR</p>
            <p class="arcs-mono text-lg font-semibold tracking-[0.2em]">{{ $passenger->reservation->pnr }}</p>
        </div>
        <div>
            <p class="text-xs uppercase text-gray-500">Issued</p>
            <p><x-station-time :at="$pass->issued_at" format="d M H:i" /></p>
        </div>
    </div>

    <div class="border-t border-dashed px-6 py-4 text-center" style="border-color: var(--arcs-border);" data-barcode="{{ $pass->barcode }}">
        <div class="inline-block">{!! $barcodeSvg !!}</div>
        <p class="mt-1 arcs-mono text-xs text-gray-600">{{ $pass->barcode }}</p>
    </div>
</div>
