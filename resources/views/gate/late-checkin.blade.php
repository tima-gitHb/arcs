{{-- Late check-in at the gate (G5, SR-29). Expects $passenger, $flight, $seatMap (null if no seat is needed). --}}
<x-app-layout>
    <x-page-header title="Late check-in" subtitle="{{ $flight->flight_no }} {{ $flight->origin }} → {{ $flight->destination }} · Gate {{ $flight->gate ?? 'TBA' }}">
        <a href="{{ route('gate.flights.show', $flight) }}" class="arcs-btn-secondary">Manifest</a>
    </x-page-header>

    <form method="POST" action="{{ route('gate.passengers.late-checkin.store', $passenger) }}" class="max-w-3xl space-y-6">
        @csrf

        <x-panel>
            <p class="text-lg font-semibold text-gray-900">{{ $passenger->first_name }} {{ $passenger->last_name }}</p>
            <p class="mt-1 text-sm text-gray-700">
                {{ $passenger->type->value }} · PNR {{ $passenger->reservation->pnr }}
                · {{ $flight->flight_no }} {{ $flight->origin }} → {{ $flight->destination }}
                · Gate {{ $flight->gate ?? 'TBA' }}
            </p>
            @if ($passenger->seatAssignment)
                <p class="mt-1 text-sm">Seat <span class="font-semibold" style="color: var(--arcs-accent-text);">{{ $passenger->seatAssignment->seat->seat_no }}</span></p>
            @endif
        </x-panel>

        @if ($seatMap)
            <x-panel title="Seat">
                <p class="text-sm">Chosen seat: <span id="seat-choice" class="font-semibold" style="color: var(--arcs-accent-text);">none</span></p>
                <input type="hidden" name="seat_id" id="seat-input" value="{{ old('seat_id') }}">
                <x-input-error :messages="$errors->get('seat_id')" class="mt-2" />

                <div class="mt-4 min-w-0">
                    <p id="seat-pick-error" class="arcs-seat-pick-error hidden mb-2" role="status"></p>
                    <x-seat-map
                        hint="Click a free seat for this passenger. Other cabins and exit rows for non-adults are not allowed."
                        data-seats="{{ $seatMap->toJson() }}"
                        data-cabin="{{ $passenger->cabin_class?->value ?? '' }}"
                        data-pax-type="{{ $passenger->type->value }}"
                    />
                </div>
            </x-panel>

            <script type="module">
                const map = document.getElementById('seat-map');
                const seats = JSON.parse(map.dataset.seats);
                const input = document.getElementById('seat-input');
                const choice = document.getElementById('seat-choice');
                const cabin = map.dataset.cabin || '';
                const paxType = map.dataset.paxType || 'Adult';
                const err = document.getElementById('seat-pick-error');

                function showErr(message) {
                    if (! err) return;
                    err.textContent = message;
                    err.classList.toggle('hidden', ! message);
                }

                function paint() {
                    const chosen = seats.find((seat) => String(seat.id) === input.value);
                    choice.textContent = chosen ? chosen.seat_no : 'none';

                    window.ArcsSeatMap.draw(map, seats, (seat) => {
                        if (seat === chosen) {
                            return { style: 'mine', disabled: true };
                        }

                        if (seat.taken) {
                            return { style: 'taken', disabled: true, title: `${seat.seat_no} · taken` };
                        }

                        const exitBlocked = Boolean(seat.exit) && paxType !== 'Adult';
                        const wrongCabin = cabin !== '' && seat.cabin !== cabin;

                        if (wrongCabin || exitBlocked) {
                            return {
                                style: 'blocked',
                                blocked: true,
                                disabled: true,
                                label: '',
                                title: exitBlocked
                                    ? `${seat.seat_no} · exit row · not allowed`
                                    : `${seat.seat_no} · ${seat.cabin} · not allowed`,
                                onPick: () => showErr(exitBlocked
                                    ? 'Exit-row seats are only for adults.'
                                    : `${seat.seat_no} is in ${seat.cabin}. This passenger is in ${cabin}.`),
                            };
                        }

                        return {
                            style: window.ArcsSeatMap.cabinStyle(seat),
                            disabled: false,
                            onPick: (picked) => {
                                showErr('');
                                input.value = picked.id;
                                paint();
                            },
                        };
                    });
                }

                paint();
            </script>
        @endif

        <x-panel title="Reason">
            <textarea name="reason" rows="3" maxlength="1000" required
                class="arcs-input mt-1">{{ old('reason') }}</textarea>
            <x-input-error :messages="$errors->get('reason')" class="mt-2" />
        </x-panel>

        @if ($entryResult->needsAcknowledgement() || $errors->has('entry_reason'))
            <x-panel title="Entry requirements">
                @include('checkin.partials.entry-reason')
            </x-panel>
        @endif

        <x-panel title="Manager approval">
            <p class="text-sm text-gray-600">A Manager types their email and password here. They are only checked, not logged in.</p>

            <div class="mt-4 rounded-lg border p-4" style="border-color: var(--arcs-accent); background: var(--arcs-accent-soft);">
                <p class="text-sm font-semibold text-gray-900">Manager signs this check-in</p>
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
        </x-panel>

        <x-primary-button>Approve and check in</x-primary-button>
    </form>
</x-app-layout>
