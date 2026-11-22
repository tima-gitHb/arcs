<x-app-layout>
    <x-slot name="aside">
        <x-seat-map-dock hint="Select a passenger, then a free seat in their cabin." :data-seats="json_encode($seatMap)" />
    </x-slot>

    <x-page-header title="Group check-in" :subtitle="$flight->flight_no.' · '.$flight->origin.' → '.$flight->destination">
        <x-status-badge :status="$flight->status" />
        <a href="{{ route('checkin.home', ['q' => $reservation->pnr]) }}" class="arcs-btn-secondary">← Back to search</a>
    </x-page-header>
    <p class="mb-6 text-sm text-gray-600">
        <span class="arcs-mono tracking-[0.2em]" style="color: var(--arcs-accent-text);">{{ $reservation->pnr }}</span>
        · <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M H:i" />
    </p>

    @if ($groupResults)
        <x-panel class="mb-6" title="Confirm all — results">
            <ul class="divide-y text-sm" style="border-color: var(--arcs-border);">
                @foreach ($groupResults as $result)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" style="border-color: var(--arcs-border);">
                        <span class="font-medium">{{ $result['name'] }}</span>
                        <x-status-badge :label="$result['message']" :status="$result['ok'] ? 'Succeeded' : 'Failed'" />
                    </li>
                @endforeach
            </ul>
            @if ($hasPasses)
                <a href="{{ route('checkin.reservations.boarding-passes', $reservation) }}" target="_blank" class="arcs-btn-primary mt-3">
                    Print all passes
                </a>
            @endif
        </x-panel>
    @endif

    <div class="space-y-4">
            <x-panel>
                <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead class="bg-gray-50 text-left text-gray-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Passenger</th>
                            <th class="px-4 py-2 font-medium">Documents</th>
                            <th class="px-4 py-2 font-medium">Entry</th>
                            <th class="px-4 py-2 font-medium">Seat</th>
                            <th class="px-4 py-2 font-medium">Bags</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($rows as $row)
                            @php($passenger = $row['passenger'])
                            <tr class="passenger-row align-top"
                                data-passenger-id="{{ $passenger->id }}"
                                data-type="{{ $passenger->type->value }}"
                                data-cabin="{{ ($passenger->cabin_class ?? \App\Enums\CabinClass::Economy)->value }}"
                                data-booked="{{ $passenger->status === \App\Enums\PassengerStatus::Booked ? '1' : '0' }}">
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</p>
                                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                        {{ $passenger->type->value }}
                                        <x-status-badge :status="$passenger->status" />
                                    </p>
                                    <a href="{{ route('checkin.passengers.show', $passenger) }}" class="text-xs font-medium hover:underline" style="color: var(--arcs-accent-text);">Open</a>
                                </td>
                                <td class="px-4 py-3">
                                    <x-status-badge :label="$row['documentStatus']" :status="$row['documentStatus'] === 'OK' ? 'Succeeded' : 'Failed'" />
                                </td>
                                <td class="px-4 py-3">
                                    <x-status-badge
                                        :label="$row['entryResult']->status->label()"
                                        :status="match (true) {
                                            $row['entryResult']->status === \App\Enums\EntryStatus::Ok => 'Succeeded',
                                            $row['entryResult']->needsAcknowledgement() => 'Failed',
                                            default => 'Delayed',
                                        }"
                                    />
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $row['entryResult']->message }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($passenger->type === \App\Enums\PassengerType::Infant)
                                        <span class="text-gray-500">Infant (no seat)</span>
                                    @else
                                        <span class="seat-label font-mono font-medium">
                                            {{ $passenger->seatAssignment?->seat->seat_no ?? 'Not chosen' }}
                                        </span>
                                        <input type="hidden" class="passenger-seat"
                                               value="{{ $passenger->seatAssignment?->seat_id }}"
                                               @disabled($passenger->status !== \App\Enums\PassengerStatus::Booked)>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    {{ $passenger->bags->count() }} bag{{ $passenger->bags->count() === 1 ? '' : 's' }}
                                    @if ($row['excess']->hasExcess())
                                        <span class="mt-1 block text-xs text-amber-800">Excess</span>
                                    @endif
                                    @if (($row['excessDueCents'] ?? 0) > 0)
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-xs font-medium" style="color: var(--arcs-accent-text);">Collect payment · {{ \App\Support\Money::format($row['excessDueCents']) }}</summary>
                                            <form method="POST" action="{{ route('checkin.excess.pay', $passenger) }}" autocomplete="off" class="mt-2">
                                                @csrf
                                                <x-card-form :amount-cents="$row['excessDueCents']" :id-prefix="'p'.$passenger->id" title="Collect payment" />
                                                <button type="submit" class="arcs-btn-primary mt-2">
                                                    Charge {{ \App\Support\Money::format($row['excessDueCents']) }}
                                                </button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </x-panel>

            @if ($canConfirm)
                <x-panel>
                <form method="POST" action="{{ route('checkin.reservations.seats', $reservation) }}" id="group-seats-form">
                    @csrf
                    <div id="seat-inputs"></div>
                    <x-input-error :messages="$errors->get('seats')" class="mb-2" />
                    <button type="submit" class="arcs-btn-secondary">
                        Save seats
                    </button>
                    <p class="mt-2 text-xs text-gray-500">Pick a passenger row, then a free seat in their booked cabin on the map.</p>
                </form>
                </x-panel>

                <x-panel>
                <form method="POST" action="{{ route('checkin.reservations.confirm-all', $reservation) }}">
                    @csrf
                    @foreach ($rows as $row)
                        @if ($row['passenger']->status === \App\Enums\PassengerStatus::Booked && $row['entryResult']->needsAcknowledgement())
                            <div class="mb-3">
                                <x-input-label :for="'entry-reason-'.$row['passenger']->id"
                                    :value="'Entry reason — '.$row['passenger']->first_name.' '.$row['passenger']->last_name" />
                                <x-text-input :id="'entry-reason-'.$row['passenger']->id"
                                    :name="'entry_reasons['.$row['passenger']->id.']'"
                                    class="mt-1 block w-full" maxlength="255" />
                            </div>
                        @endif
                    @endforeach
                    <button type="submit" class="arcs-btn-primary">
                        Confirm all
                    </button>
                </form>
                </x-panel>
            @elseif ($hasPasses)
                <a href="{{ route('checkin.reservations.boarding-passes', $reservation) }}" target="_blank" class="arcs-btn-primary">
                    Print all passes
                </a>
            @endif
    </div>

    {{-- type="module": runs after the Vite bundle, which provides window.ArcsSeatMap. --}}
    <script type="module">
        const seatData = @json($seatMap);
        const rows = () => [...document.querySelectorAll('.passenger-row')];
        let activeId = rows().find((row) => row.dataset.booked === '1' && row.dataset.type !== 'Infant')?.dataset.passengerId ?? null;

        function chosenSeats() {
            const chosen = new Map();
            rows().forEach((row) => {
                const input = row.querySelector('.passenger-seat');
                if (input && input.value !== '') {
                    chosen.set(input.value, row.dataset.passengerId);
                }
            });
            return chosen;
        }

        function paintMap() {
            rows().forEach((row) => {
                row.classList.toggle('ring-2', row.dataset.passengerId === activeId);
                row.classList.toggle('ring-navy', row.dataset.passengerId === activeId);
                const input = row.querySelector('.passenger-seat');
                const label = row.querySelector('.seat-label');
                if (input && label) {
                    const seat = seatData.find((item) => String(item.id) === input.value);
                    label.textContent = seat ? seat.seat_no : 'Not chosen';
                }
            });

            const map = document.getElementById('seat-map');
            const hint = document.getElementById('seat-map-hint');
            const active = rows().find((row) => row.dataset.passengerId === activeId);
            const cabin = active?.dataset.cabin || '';
            const paxType = active?.dataset.type || 'Adult';
            const chosen = chosenSeats();

            hint.textContent = !active || active.dataset.type === 'Infant'
                ? 'Select a non-infant passenger to assign a seat.'
                : `Assigning ${cabin} for ${active.querySelector('.font-medium').textContent.trim()} · whole cabin.`;

            if (!active || active.dataset.type === 'Infant' || seatData.length === 0) {
                map.replaceChildren();
                return;
            }

            window.ArcsSeatMap.draw(map, seatData, (seat) => {
                const owner = chosen.get(String(seat.id));
                const mine = owner === activeId;
                const exitBlocked = Boolean(seat.exit) && paxType !== 'Adult';
                const wrongCabin = cabin !== '' && seat.cabin !== cabin;
                let style = window.ArcsSeatMap.cabinStyle({ ...seat, taken: false });
                let disabled = false;
                let blocked = false;
                let label = seat.letter;
                let title = '';

                if (seat.taken || (owner && ! mine)) {
                    style = owner && ! mine ? 'party' : 'taken';
                    disabled = true;
                    label = '';
                    title = `${seat.seat_no} · taken`;
                } else if (mine) {
                    style = 'mine';
                    disabled = false;
                    title = `${seat.seat_no} · this passenger`;
                } else if (wrongCabin || exitBlocked) {
                    style = 'blocked';
                    blocked = true;
                    disabled = true;
                    label = '';
                    title = exitBlocked
                        ? `${seat.seat_no} · exit row · not allowed`
                        : `${seat.seat_no} · ${seat.cabin} · not allowed`;
                }

                return { style, disabled, blocked, label, title, onPick: pickSeat };
            });
        }

        function pickSeat(seat) {
            if (seat.taken) return;
            const row = rows().find((item) => item.dataset.passengerId === activeId);
            if (!row || row.dataset.type === 'Infant' || row.dataset.booked !== '1') return;
            if (seat.cabin !== row.dataset.cabin) return;

            const input = row.querySelector('.passenger-seat');
            const owner = chosenSeats().get(String(seat.id));
            if (owner && owner !== activeId) return;

            input.value = input.value === String(seat.id) ? '' : String(seat.id);
            paintMap();
        }

        rows().forEach((row) => {
            row.addEventListener('click', (event) => {
                if (event.target.closest('a')) return;
                if (row.dataset.type === 'Infant' || row.dataset.booked !== '1') return;
                activeId = row.dataset.passengerId;
                paintMap();
            });
        });

        document.getElementById('group-seats-form')?.addEventListener('submit', () => {
            const box = document.getElementById('seat-inputs');
            box.replaceChildren();
            rows().forEach((row) => {
                const input = row.querySelector('.passenger-seat');
                if (!input || input.value === '') return;
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = `seats[${row.dataset.passengerId}]`;
                hidden.value = input.value;
                box.appendChild(hidden);
            });
        });

        paintMap();
    </script>
</x-app-layout>
