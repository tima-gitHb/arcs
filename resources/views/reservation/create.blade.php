@php
    $adultOptions = [];

    foreach ($passengers as $optionIndex => $optionPassenger) {
        if (($optionPassenger['type'] ?? '') !== 'Adult') {
            continue;
        }

        $optionName = trim(($optionPassenger['first_name'] ?? '').' '.($optionPassenger['last_name'] ?? ''));
        $adultOptions[$optionIndex] = $optionName !== '' ? $optionName : 'Passenger '.((int) $optionIndex + 1);
    }
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">New reservation</h1>
    </x-slot>

    <form method="POST" action="{{ route('reservation.store') }}" class="max-w-6xl rounded-lg bg-white p-6 shadow-sm" autocomplete="off">
        @csrf

        <p class="text-sm text-gray-600">
            Choose a flight, then add each passenger. An infant must be linked to an adult on this reservation.
        </p>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                Check the form and try again.
            </div>
        @endif

        <div class="mt-6">
            <x-input-label for="flight_id" value="Flight" />
            <select
                id="flight_id"
                name="flight_id"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                required
                @disabled($flights->isEmpty())
            >
                <option value="">Choose a flight</option>
                @foreach ($flightGroups as $status => $group)
                    <optgroup label="{{ App\Enums\FlightStatus::from($status)->label() }}">
                        @foreach ($group as $flight)
                            <option value="{{ $flight->id }}" @selected((string) old('flight_id') === (string) $flight->id)>
                                {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }} · {{ $flight->departure_at->timezone(config('app.timezone'))->format('d M Y H:i') }}
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <p class="mt-2 text-sm text-gray-500">Scheduled, delayed, check-in open, boarding, and closed flights can be booked. Departed and cancelled flights are not listed.</p>
            <x-input-error :messages="$errors->get('flight_id')" class="mt-2" />
            @if ($flights->isEmpty())
                <p class="mt-2 text-sm text-gray-500">No flights are open for reservation.</p>
            @endif
        </div>

        <div class="mt-6 grid items-start gap-6 md:grid-cols-[minmax(0,1fr)_20rem]">
        <div>
        <div id="passengers" class="space-y-4">
            @foreach ($passengers as $index => $passenger)
                @include('reservation.partials.passenger-row', [
                    'index' => $index,
                    'passenger' => $passenger,
                    'adultOptions' => $adultOptions,
                    'canRemove' => count($passengers) > 1,
                ])
            @endforeach
        </div>

        <x-input-error :messages="$errors->get('passengers')" class="mt-4" />

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <button
                type="button"
                id="add-passenger"
                class="rounded border border-navy px-4 py-2 text-sm font-medium text-navy hover:bg-navy/5"
            >
                Add passenger
            </button>
            <button
                type="submit"
                class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90 disabled:cursor-not-allowed disabled:opacity-50"
                @disabled($flights->isEmpty())
            >
                Create reservation
            </button>
        </div>
        </div>

        <aside id="seat-map-panel" class="rounded-[2rem] bg-[#14213d] px-4 py-5 text-white md:sticky md:top-4">
            <div class="mx-auto mb-3 h-8 w-20 rounded-t-full bg-[#2c406e]"></div>
            <p class="text-center text-xs uppercase tracking-[0.2em] text-white/70">Cabin</p>
            <h2 class="mt-1 text-center text-lg font-semibold">Seat map</h2>
            <p id="seat-map-hint" class="mt-1 text-center text-xs text-white/70">Choose a flight, then a passenger, then a free seat.</p>
            <x-input-error :messages="$errors->get('seat')" class="mt-3" />
            <div id="seat-map" class="mt-4 max-h-[70vh] overflow-y-auto pr-1"></div>
            <div class="mt-4 grid grid-cols-2 gap-2 text-xs text-white/80">
                <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-white"></span> Economy</span>
                <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-amber-200"></span> Business</span>
                <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-emerald-400"></span> Selected</span>
                <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-slate-600"></span> Taken</span>
            </div>
        </aside>
        </div>
    </form>

    <template id="passenger-template">
        @include('reservation.partials.passenger-row', [
            'index' => '__INDEX__',
            'passenger' => [
                'first_name' => '',
                'last_name' => '',
                'type' => 'Adult',
                'date_of_birth' => '',
                'accompanying_adult' => '',
            ],
            'adultOptions' => [],
            'canRemove' => true,
        ])
    </template>

    <script>
        const list = document.getElementById('passengers');
        const template = document.getElementById('passenger-template');
        const addButton = document.getElementById('add-passenger');

        const rows = () => [...list.querySelectorAll('.passenger-row')];

        function refreshAdults() {
            const adults = rows().flatMap((row) => {
                if (row.querySelector('.passenger-type').value !== 'Adult') {
                    return [];
                }

                const first = row.querySelector('.passenger-first').value.trim();
                const last = row.querySelector('.passenger-last').value.trim();
                const name = `${first} ${last}`.trim();

                return [{
                    index: row.dataset.index,
                    label: name || `Passenger ${Number(row.dataset.index) + 1}`,
                }];
            });

            rows().forEach((row) => {
                const type = row.querySelector('.passenger-type').value;
                row.querySelector('.accompanying-field').classList.toggle('hidden', type !== 'Infant');
                row.querySelector('.seat-field').classList.toggle('hidden', type === 'Infant');

                if (type === 'Infant') {
                    row.querySelector('.passenger-seat').value = '';
                }

                const select = row.querySelector('.accompanying-adult');
                const current = select.value;
                select.replaceChildren();

                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Select an adult';
                select.appendChild(placeholder);

                adults.forEach((adult) => {
                    const option = document.createElement('option');
                    option.value = adult.index;
                    option.textContent = adult.label;
                    option.selected = adult.index === current;
                    select.appendChild(option);
                });
            });
        }

        function reindex() {
            rows().forEach((row, index) => {
                row.dataset.index = String(index);
                row.querySelector('.passenger-number').textContent = String(index + 1);

                row.querySelectorAll('[name]').forEach((field) => {
                    field.name = field.name
                        .replace(/passengers\[__INDEX__\]/, `passengers[${index}]`)
                        .replace(/passengers\[\d+\]/, `passengers[${index}]`);
                });

                row.querySelectorAll('[id]').forEach((field) => {
                    field.id = field.id.replace(/__INDEX__|\d+/, String(index));
                });

                row.querySelectorAll('label[for]').forEach((label) => {
                    label.htmlFor = label.htmlFor.replace(/__INDEX__|\d+/, String(index));
                });
            });

            const onlyOne = rows().length < 2;
            rows().forEach((row) => {
                row.querySelector('.remove-passenger').classList.toggle('hidden', onlyOne);
            });

            refreshAdults();
            paintMap();
        }

        let seatData = [];
        let activeIndex = '0';
        let mapRequest = 0;

        const seatStyles = {
            business: 'bg-amber-200 text-amber-950 hover:bg-amber-100',
            economy: 'bg-white text-navy hover:bg-sky-100',
            taken: 'cursor-not-allowed bg-slate-600 text-slate-400',
            selected: 'bg-emerald-400 text-emerald-950',
            mine: 'bg-emerald-400 text-emerald-950 ring-2 ring-white',
        };

        function chosenSeats() {
            const chosen = new Map();

            rows().forEach((row) => {
                const seatId = row.querySelector('.passenger-seat').value;

                if (seatId !== '') {
                    chosen.set(seatId, row.dataset.index);
                }
            });

            return chosen;
        }

        function paintMap() {
            const available = rows().find((row) => row.querySelector('.passenger-type').value !== 'Infant');

            if (!rows().some((row) => row.dataset.index === activeIndex && row.querySelector('.passenger-type').value !== 'Infant')) {
                activeIndex = available ? available.dataset.index : '0';
            }

            const chosen = chosenSeats();

            rows().forEach((row) => {
                const input = row.querySelector('.passenger-seat');
                const seat = seatData.find((item) => String(item.id) === input.value);
                const label = row.querySelector('.seat-label');
                label.textContent = seat ? seat.seat_no : 'Not chosen';
                row.classList.toggle('ring-2', row.dataset.index === activeIndex);
                row.classList.toggle('ring-navy', row.dataset.index === activeIndex);
            });

            const map = document.getElementById('seat-map');
            const hint = document.getElementById('seat-map-hint');
            map.replaceChildren();

            if (seatData.length === 0) {
                hint.textContent = 'Choose a flight to see the seat map.';

                return;
            }

            hint.textContent = `Choosing for passenger ${Number(activeIndex) + 1}. Taken seats stay blocked.`;

            const byRow = new Map();
            seatData.forEach((seat) => {
                if (!byRow.has(seat.row)) {
                    byRow.set(seat.row, []);
                }

                byRow.get(seat.row).push(seat);
            });

            const header = document.createElement('div');
            header.className = 'mb-2 grid grid-cols-[1.5rem_repeat(3,1fr)_1rem_repeat(3,1fr)] gap-1 text-center text-[10px] uppercase text-white/50';
            ['', 'A', 'B', 'C', '', 'D', 'E', 'F'].forEach((letter) => {
                const cell = document.createElement('span');
                cell.textContent = letter;
                header.appendChild(cell);
            });
            map.appendChild(header);

            [...byRow.keys()].sort((left, right) => left - right).forEach((rowNo) => {
                const line = document.createElement('div');
                line.className = 'mb-1 grid grid-cols-[1.5rem_repeat(3,1fr)_1rem_repeat(3,1fr)] items-center gap-1';

                const number = document.createElement('span');
                number.className = 'text-center text-[10px] text-white/50';
                number.textContent = String(rowNo);
                line.appendChild(number);

                ['A', 'B', 'C', 'D', 'E', 'F'].forEach((letter, position) => {
                    if (position === 3) {
                        line.appendChild(document.createElement('span'));
                    }

                    const seat = byRow.get(rowNo).find((item) => item.letter === letter);
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = letter;

                    if (!seat) {
                        button.disabled = true;
                        button.className = 'h-7 rounded bg-white/5';
                        line.appendChild(button);

                        return;
                    }

                    const owner = chosen.get(String(seat.id));
                    const mine = owner === activeIndex;
                    let style = seat.cabin === 'Business' ? seatStyles.business : seatStyles.economy;

                    if (seat.taken || (owner && !mine)) {
                        style = seatStyles.taken;
                        button.disabled = true;
                    }

                    if (mine) {
                        style = seatStyles.mine;
                        button.disabled = false;
                    } else if (owner) {
                        style = seatStyles.selected;
                    }

                    button.className = `h-7 rounded text-[11px] font-semibold ${style}`;
                    button.title = seat.taken ? `${seat.seat_no} taken` : seat.seat_no;
                    button.addEventListener('click', () => pickSeat(seat));
                    line.appendChild(button);
                });

                map.appendChild(line);

                if (rowNo === 12) {
                    const wing = document.createElement('div');
                    wing.className = 'my-2 h-2 rounded-full bg-white/20';
                    map.appendChild(wing);
                }
            });
        }

        function pickSeat(seat) {
            if (seat.taken) {
                return;
            }

            const row = rows().find((item) => item.dataset.index === activeIndex);

            if (!row || row.querySelector('.passenger-type').value === 'Infant') {
                return;
            }

            const input = row.querySelector('.passenger-seat');
            const owner = chosenSeats().get(String(seat.id));

            if (owner && owner !== activeIndex) {
                return;
            }

            input.value = input.value === String(seat.id) ? '' : String(seat.id);
            paintMap();
        }

        async function loadMap() {
            const request = ++mapRequest;
            const flightId = document.getElementById('flight_id').value;
            seatData = [];

            if (flightId === '') {
                paintMap();

                return;
            }

            const response = await fetch(`/reservation/flights/${flightId}/seats`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (request !== mapRequest) {
                return;
            }

            if (response.ok) {
                const body = await response.json();
                seatData = body.seats;
            }

            rows().forEach((row) => {
                const input = row.querySelector('.passenger-seat');
                const seat = seatData.find((item) => String(item.id) === input.value);

                if (input.value !== '' && (!seat || seat.taken)) {
                    input.value = '';
                }
            });

            paintMap();
        }

        document.getElementById('flight_id').addEventListener('change', () => {
            rows().forEach((row) => {
                row.querySelector('.passenger-seat').value = '';
            });
            loadMap();
        });

        addButton.addEventListener('click', () => {
            list.appendChild(template.content.cloneNode(true));
            reindex();
            list.lastElementChild.querySelector('.passenger-first').focus();
        });

        list.addEventListener('click', (event) => {
            const pick = event.target.closest('.pick-seat');

            if (pick) {
                const row = pick.closest('.passenger-row');

                if (row.querySelector('.passenger-type').value !== 'Infant') {
                    activeIndex = row.dataset.index;
                    paintMap();
                    document.getElementById('seat-map-panel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }

                return;
            }

            const button = event.target.closest('.remove-passenger');

            if (!button || rows().length < 2) {
                return;
            }

            button.closest('.passenger-row').remove();
            reindex();
        });

        list.addEventListener('input', refreshAdults);
        list.addEventListener('change', () => {
            refreshAdults();
            paintMap();
        });

        reindex();
        loadMap();
    </script>
</x-app-layout>
