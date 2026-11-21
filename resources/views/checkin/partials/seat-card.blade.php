{{--
    Seat card on the check-in screen (C3: SR-7, C6: SR-20). Expects $passenger, $seatMap, $changingSeat,
    and $flight: the flight the seat is for (B13b; a connection has a seat per flight).
    Booked: the map is open and a click assigns the seat.
    CheckedIn: "Change seat" opens the map, limited to the same cabin; the boarding pass is replaced.
    The plane itself is rendered in the app-shell aside by the parent show view, on this step only.
--}}
@php
    $flight ??= $passenger->reservation->flight;
    $currentSeat = $passenger->seatAssignment?->seat;
    $picking = $passenger->status === \App\Enums\PassengerStatus::Booked;
    $canChange = $passenger->status === \App\Enums\PassengerStatus::CheckedIn
        && $currentSeat
        && ! $flight->isBoardingClosed()
        && $flight->status !== \App\Enums\FlightStatus::Cancelled;
    $showMap = $picking || ($canChange && $changingSeat);
@endphp

<x-panel>
    <h2 class="text-base font-semibold text-gray-900">Seat @if (($journeyFlights ?? collect())->count() > 1)on {{ $flight->flight_no }}@endif</h2>

    @if ($passenger->type === \App\Enums\PassengerType::Infant)
        <p class="mt-2 text-sm text-gray-600">An infant has no seat.</p>
    @else
        <p class="mt-2 text-sm">
            Current seat:
            <span class="font-semibold" style="color: var(--arcs-accent-text);">{{ $currentSeat?->seat_no ?? 'none' }}</span>
            @if ($currentSeat)
                <span class="text-gray-500">({{ $currentSeat->cabin_class->value }})</span>
            @endif
        </p>

        @if ($canChange && ! $changingSeat)
            <a href="{{ route('checkin.passengers.show', array_filter([$passenger, 'change_seat' => 1, 'flight' => request('flight')])) }}"
               class="arcs-btn-secondary mt-3">
                Change seat
            </a>
        @endif

        @if ($showMap)
            <form id="seat-form" method="POST" action="{{ $picking ? route('checkin.seats.assign', $passenger) : route('checkin.seats.change', $passenger) }}">
                @csrf
                <input type="hidden" name="seat_id" id="seat-input" value="{{ $currentSeat?->id }}">
                @if (($journeyFlights ?? collect())->count() > 1)
                    <input type="hidden" name="flight_id" value="{{ $flight->id }}">
                @endif
            </form>
            <p class="mt-2 text-sm text-gray-600">
                {{ $picking ? 'Click a free seat on the plane to assign it.' : 'Click a free seat in the same cabin on the plane. A new boarding pass replaces the old one.' }}
            </p>
            @unless ($picking)
                <a href="{{ route('checkin.passengers.show', array_filter([$passenger, 'flight' => request('flight')])) }}" class="mt-2 inline-block text-sm text-gray-600 hover:underline">Cancel</a>
            @endunless
        @endif
    @endif
</x-panel>
