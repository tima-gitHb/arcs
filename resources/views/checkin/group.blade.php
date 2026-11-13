<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-navy">Group check-in</h1>
                <p class="mt-1 text-sm text-gray-600">
                    <span class="font-mono tracking-[0.2em] text-navy">{{ $reservation->pnr }}</span>
                    · {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}
                    · <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M H:i" />
                </p>
            </div>
            <a href="{{ route('checkin.home', ['q' => $reservation->pnr]) }}" class="text-sm font-medium text-navy hover:underline">
                ← Back to search
            </a>
        </div>
    </x-slot>

    @if ($groupResults)
        <div class="mb-6 rounded-lg bg-white p-4 shadow-sm">
            <h2 class="text-lg font-semibold text-navy">Confirm all — results</h2>
            <ul class="mt-3 divide-y divide-gray-100 text-sm">
                @foreach ($groupResults as $result)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="font-medium">{{ $result['name'] }}</span>
                        @if ($result['ok'])
                            <span class="rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">{{ $result['message'] }}</span>
                        @else
                            <span class="rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">{{ $result['message'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($hasPasses)
                <a href="{{ route('checkin.reservations.boarding-passes', $reservation) }}" target="_blank"
                   class="mt-3 inline-block rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">
                    Print all passes
                </a>
            @endif
        </div>
    @endif

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-4">
            <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                <table class="w-full text-sm">
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
                                    <p class="text-xs text-gray-500">{{ $passenger->type->value }} · {{ $passenger->status->label() }}</p>
                                    <a href="{{ route('checkin.passengers.show', $passenger) }}" class="text-xs font-medium text-navy hover:underline">Open</a>
                                </td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'text-green-800' => $row['documentStatus'] === 'OK',
                                        'text-red-800' => $row['documentStatus'] !== 'OK',
                                    ])>{{ $row['documentStatus'] }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'text-green-800' => $row['entryResult']->status === \App\Enums\EntryStatus::Ok,
                                        'text-red-800' => $row['entryResult']->needsAcknowledgement(),
                                        'text-amber-800' => $row['entryResult']->status === \App\Enums\EntryStatus::Unknown,
                                    ])>{{ $row['entryResult']->status->label() }}</span>
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
                                            <summary class="cursor-pointer text-xs font-medium text-navy">Collect payment · {{ \App\Support\Money::format($row['excessDueCents']) }}</summary>
                                            <form method="POST" action="{{ route('checkin.excess.pay', $passenger) }}" autocomplete="off" class="mt-2">
                                                @csrf
                                                <x-card-form :amount-cents="$row['excessDueCents']" :id-prefix="'p'.$passenger->id" title="Collect payment" class="shadow-none border border-amber-200 p-3" />
                                                <button type="submit" class="mt-2 rounded bg-navy px-3 py-1.5 text-xs font-medium text-white hover:bg-navy/90">
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

            @if ($canConfirm)
                <form method="POST" action="{{ route('checkin.reservations.seats', $reservation) }}" id="group-seats-form" class="rounded-lg bg-white p-4 shadow-sm">
                    @csrf
                    <div id="seat-inputs"></div>
                    <x-input-error :messages="$errors->get('seats')" class="mb-2" />
                    <button type="submit" class="rounded border border-navy px-4 py-2 text-sm font-medium text-navy hover:bg-navy hover:text-white">
                        Save seats
                    </button>
                    <p class="mt-2 text-xs text-gray-500">Pick a passenger row, then a free seat in their booked cabin on the map.</p>
                </form>

                <form method="POST" action="{{ route('checkin.reservations.confirm-all', $reservation) }}" class="rounded-lg bg-white p-4 shadow-sm">
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
                    <button type="submit" class="rounded bg-navy px-5 py-2.5 text-sm font-semibold text-white hover:bg-navy/90">
                        Confirm all
                    </button>
                </form>
            @elseif ($hasPasses)
                <a href="{{ route('checkin.reservations.boarding-passes', $reservation) }}" target="_blank"
                   class="inline-block rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">
                    Print all passes
                </a>
            @endif
        </div>

        <x-seat-map hint="Select a passenger, then a free seat in their cabin." :data-seats="json_encode($seatMap)" />
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
            const offered = seatData.filter((seat) => seat.cabin === cabin);
            const chosen = chosenSeats();

            hint.textContent = !active || active.dataset.type === 'Infant'
                ? 'Select a non-infant passenger to assign a seat.'
                : `Assigning ${cabin} for ${active.querySelector('.font-medium').textContent.trim()}.`;

            if (!active || active.dataset.type === 'Infant' || offered.length === 0) {
                map.replaceChildren();
                return;
            }

            window.ArcsSeatMap.draw(map, offered, (seat) => {
                const owner = chosen.get(String(seat.id));
                const mine = owner === activeId;
                let style = window.ArcsSeatMap.cabinStyle({ ...seat, taken: false });
                let disabled = false;

                if (seat.taken || (owner && !mine)) {
                    style = 'taken';
                    disabled = true;
                }
                if (mine) {
                    style = 'mine';
                    disabled = false;
                } else if (owner) {
                    style = 'selected';
                }

                return { style, disabled, onPick: pickSeat };
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
