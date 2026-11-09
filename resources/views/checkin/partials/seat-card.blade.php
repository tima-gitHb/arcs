{{--
    Seat card on the check-in screen (C3: SR-7, C6: SR-20). Expects $passenger, $seatMap, $changingSeat.
    Booked: the map is open and a click assigns the seat.
    CheckedIn: "Change seat" opens the map, limited to the same cabin; the boarding pass is replaced.
    The plane itself is rendered in the sticky column by the parent show view.
--}}
@php
    $currentSeat = $passenger->seatAssignment?->seat;
    $picking = $passenger->status === \App\Enums\PassengerStatus::Booked;
    $canChange = $passenger->status === \App\Enums\PassengerStatus::CheckedIn
        && $currentSeat
        && ! $passenger->reservation->flight->isBoardingClosed()
        && $passenger->reservation->flight->status !== \App\Enums\FlightStatus::Cancelled;
    $showMap = $picking || ($canChange && $changingSeat);
@endphp

<div class="rounded-lg bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-navy">Seat</h2>

    @if ($passenger->type === \App\Enums\PassengerType::Infant)
        <p class="mt-2 text-sm text-gray-600">An infant has no seat.</p>
    @else
        <p class="mt-2 text-sm">
            Current seat:
            <span class="font-semibold text-navy">{{ $currentSeat?->seat_no ?? 'none' }}</span>
            @if ($currentSeat)
                <span class="text-gray-500">({{ $currentSeat->cabin_class->value }})</span>
            @endif
        </p>

        @if ($canChange && ! $changingSeat)
            <a href="{{ route('checkin.passengers.show', [$passenger, 'change_seat' => 1]) }}"
               class="mt-3 inline-block rounded border border-navy px-4 py-2 text-sm font-medium text-navy hover:bg-navy hover:text-white">
                Change seat
            </a>
        @endif

        @if ($showMap)
            <form id="seat-form" method="POST" action="{{ $picking ? route('checkin.seats.assign', $passenger) : route('checkin.seats.change', $passenger) }}">
                @csrf
                <input type="hidden" name="seat_id" id="seat-input" value="{{ $currentSeat?->id }}">
            </form>
            <p class="mt-2 text-sm text-gray-600">
                {{ $picking ? 'Click a free seat on the plane to assign it.' : 'Click a free seat in the same cabin on the plane. A new boarding pass replaces the old one.' }}
            </p>
            @unless ($picking)
                <a href="{{ route('checkin.passengers.show', $passenger) }}" class="mt-2 inline-block text-sm text-gray-600 hover:underline">Cancel</a>
            @endunless
        @endif
    @endif
</div>
