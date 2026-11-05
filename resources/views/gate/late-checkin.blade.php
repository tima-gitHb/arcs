{{-- Late check-in at the gate (G5, SR-29). Expects $passenger, $flight, $seatMap (null if no seat is needed). --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h1 class="text-2xl font-semibold text-navy">Late check-in</h1>
            <a href="{{ route('gate.flights.show', $flight) }}" class="text-sm font-medium text-navy hover:underline">← Manifest</a>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('gate.passengers.late-checkin.store', $passenger) }}" class="max-w-3xl space-y-6">
        @csrf

        <div class="rounded-lg bg-white p-6 shadow-sm text-sm">
            <p class="text-lg font-semibold">{{ $passenger->first_name }} {{ $passenger->last_name }}</p>
            <p class="mt-1 text-gray-700">
                {{ $passenger->type->value }} · PNR {{ $passenger->reservation->pnr }}
                · {{ $flight->flight_no }} {{ $flight->origin }} → {{ $flight->destination }}
                · Gate {{ $flight->gate ?? 'TBA' }}
            </p>
            @if ($passenger->seatAssignment)
                <p class="mt-1">Seat <span class="font-semibold text-navy">{{ $passenger->seatAssignment->seat->seat_no }}</span></p>
            @endif
        </div>

        @if ($seatMap)
            <div class="rounded-lg bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-navy">Seat</h2>
                <p class="mt-1 text-sm">Chosen seat: <span id="seat-choice" class="font-semibold text-navy">none</span></p>
                <input type="hidden" name="seat_id" id="seat-input" value="{{ old('seat_id') }}">
                <x-input-error :messages="$errors->get('seat_id')" class="mt-2" />

                <div class="mt-4 max-w-sm">
                    <x-seat-map hint="Click a free seat for this passenger." data-seats="{{ $seatMap->toJson() }}" />
                </div>
            </div>

            <script type="module">
                const map = document.getElementById('seat-map');
                const seats = JSON.parse(map.dataset.seats);
                const input = document.getElementById('seat-input');
                const choice = document.getElementById('seat-choice');

                function paint() {
                    const chosen = seats.find((seat) => String(seat.id) === input.value);
                    choice.textContent = chosen ? chosen.seat_no : 'none';

                    window.ArcsSeatMap.draw(map, seats, (seat) => ({
                        style: seat === chosen ? 'mine' : window.ArcsSeatMap.cabinStyle(seat),
                        disabled: seat.taken,
                        onPick: (picked) => {
                            input.value = picked.id;
                            paint();
                        },
                    }));
                }

                paint();
            </script>
        @endif

        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-navy">Reason</h2>
            <textarea name="reason" rows="3" maxlength="1000" required
                class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-navy focus:ring-navy">{{ old('reason') }}</textarea>
            <x-input-error :messages="$errors->get('reason')" class="mt-2" />
        </div>

        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-navy">Manager approval</h2>
            <p class="mt-1 text-sm text-gray-600">A Manager types their email and password here. They are only checked, not logged in.</p>

            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="manager_email" value="Manager email" />
                    <x-text-input id="manager_email" name="manager_email" type="email" class="mt-1 block w-full"
                        :value="old('manager_email')" required autocomplete="off" />
                    <x-input-error :messages="$errors->get('manager_email')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="password" value="Manager password" />
                    <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>
            </div>
        </div>

        <button type="submit" class="rounded bg-navy px-5 py-2.5 text-sm font-semibold text-white hover:bg-navy/90">
            Approve and check in
        </button>
    </form>
</x-app-layout>
