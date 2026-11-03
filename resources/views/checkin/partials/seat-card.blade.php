{{-- Seat card on the check-in screen (C3, SR-7). Expects $passenger and $seatMap. --}}
@php($currentSeat = $passenger->seatAssignment?->seat)

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

        @if ($passenger->checkinDetailsEditable())
            <div class="mt-4 max-w-sm">
                <form id="seat-form" method="POST" action="{{ route('checkin.seats.assign', $passenger) }}">
                    @csrf
                    <input type="hidden" name="seat_id" id="seat-input" value="{{ $currentSeat?->id }}">
                </form>

                <x-seat-map
                    hint="Click a free seat to assign it. Taken seats stay blocked."
                    data-seats="{{ $seatMap->toJson() }}"
                    data-selected-seat="{{ $currentSeat?->id }}"
                />
            </div>

            <script type="module">
                const map = document.getElementById('seat-map');
                const seats = JSON.parse(map.dataset.seats);
                const selected = map.dataset.selectedSeat;
                const form = document.getElementById('seat-form');

                window.ArcsSeatMap.draw(map, seats, (seat) => {
                    if (String(seat.id) === selected) {
                        return { style: 'mine', disabled: true };
                    }

                    return {
                        style: window.ArcsSeatMap.cabinStyle(seat),
                        disabled: seat.taken,
                        onPick: (picked) => {
                            document.getElementById('seat-input').value = picked.id;
                            form.submit();
                        },
                    };
                });
            </script>
        @endif
    @endif
</div>
