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

    <div class="mb-6 max-w-6xl">
        <x-todays-flights :role="'reservation'" />
    </div>

    <form method="POST" action="{{ route('reservation.store') }}" class="max-w-6xl" autocomplete="off" id="booking-form">
        @csrf

        @if (! $pricesReady)
            <div class="mb-4 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
                Fares are not published. Ask a manager to set prices (M7), then refresh.
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                @if ($errors->has('card_number') && ! $errors->has('flight_id') && ! $errors->has('passengers'))
                    {{ $errors->first('card_number') }}
                @else
                    Fix the highlighted fields, then charge again.
                @endif
            </div>
        @endif

        <div class="rounded-lg bg-white p-6 shadow-sm">
            <p class="text-sm text-gray-600">
                Desk / phone booking: capture flight and passengers from the caller, assign seats, read the quote back, then take the card. PNR is created only after approval.
            </p>

            <div class="mt-6">
                <x-input-label for="flight_id" value="Flight" />
                <select
                    id="flight_id"
                    name="flight_id"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-navy focus:ring-navy"
                    required
                    @disabled($flights->isEmpty())
                >
                    <option value="">Select flight…</option>
                    @foreach ($flightGroups as $status => $group)
                        <optgroup label="{{ App\Enums\FlightStatus::from($status)->label() }}">
                            @foreach ($group as $flight)
                                <option value="{{ $flight->id }}" @selected((string) old('flight_id') === (string) $flight->id)>
                                    {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }} · {{ $flight->departureLocal()->format('d M Y H:i') }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="mt-2 text-sm text-gray-500">Bookable: Scheduled, Delayed, Check-in open, Boarding, Closed. Not listed: Departed, Cancelled.</p>
                <x-input-error :messages="$errors->get('flight_id')" class="mt-2" />
                @if ($flights->isEmpty())
                    <p class="mt-2 text-sm text-gray-500">No bookable flights right now.</p>
                @endif
            </div>
        </div>

        <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-6">
                <div id="passengers" class="space-y-4">
                    @foreach ($passengers as $index => $passenger)
                        @include('reservation.partials.passenger-row', [
                            'index' => $index,
                            'passenger' => $passenger,
                            'adultOptions' => $adultOptions,
                            'canRemove' => count($passengers) > 1,
                            'cabinOptions' => $cabinOptions,
                        ])
                    @endforeach
                </div>

                <x-input-error :messages="$errors->get('passengers')" class="mt-2" />

                <button
                    type="button"
                    id="add-passenger"
                    class="rounded border border-navy px-4 py-2 text-sm font-medium text-navy hover:bg-navy/5"
                >
                    Add passenger
                </button>

                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-navy">Caller contact</h2>
                    <p class="mt-1 text-sm text-gray-600">Optional. Email sends the e-ticket / receipt after the PNR is saved.</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="contact_email" value="Email for e-ticket" />
                            <x-text-input id="contact_email" name="contact_email" type="email" class="mt-1 block w-full"
                                :value="old('contact_email')" maxlength="255" autocomplete="off" placeholder="caller@…" />
                            <x-input-error :messages="$errors->get('contact_email')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="contact_phone" value="Callback phone" />
                            <x-text-input id="contact_phone" name="contact_phone" type="text" class="mt-1 block w-full"
                                :value="old('contact_phone')" maxlength="20" autocomplete="off" placeholder="+961…" />
                            <x-input-error :messages="$errors->get('contact_phone')" class="mt-2" />
                        </div>
                    </div>
                </div>

                <x-card-form amount-label="$0.00" />

                <div>
                    <button
                        type="submit"
                        class="rounded bg-navy px-5 py-2.5 text-sm font-semibold text-white hover:bg-navy/90 disabled:cursor-not-allowed disabled:opacity-50"
                        @disabled($flights->isEmpty() || ! $pricesReady)
                    >
                        Charge <span id="pay-button-amount">$0.00</span> and save PNR
                    </button>
                    <p class="mt-2 text-sm text-gray-500">Declined card → no PNR created.</p>
                </div>
            </div>

            <aside class="space-y-4">
                <x-seat-map hint="Select flight → select passenger → assign a free seat in that cabin.">
                    <x-input-error :messages="$errors->get('seat')" class="mt-3" />
                </x-seat-map>

                <div class="rounded-lg border border-navy/10 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-navy">Fare quote</h2>
                    <p class="mt-1 text-xs text-gray-500">Read this total back to the caller before charging.</p>
                    <p id="summary-flight" class="mt-2 text-sm font-medium text-gray-700">No flight selected</p>

                    <ul id="price-lines" class="mt-4 space-y-2 border-t border-gray-100 pt-4 text-sm text-gray-800"></ul>

                    <div class="mt-4 flex items-end justify-between border-t border-gray-100 pt-4">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Amount to charge</p>
                            <p class="text-2xl font-semibold text-navy"><span id="price-total">$0.00</span></p>
                            <p class="text-xs text-gray-500">USD · fare + prepaid extras</p>
                        </div>
                    </div>
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
                'cabin_class' => 'Economy',
                'date_of_birth' => '',
                'accompanying_adult' => '',
                'extra_bags' => 0,
                'extra_kg' => 0,
                'passport' => [
                    'number' => '',
                    'issuing_country' => null,
                    'expiry_date' => '',
                ],
            ],
            'adultOptions' => [],
            'canRemove' => true,
            'cabinOptions' => $cabinOptions,
        ])
    </template>

    {{-- type="module": runs after the Vite bundle, which provides window.ArcsSeatMap. --}}
    <script type="module">
        const list = document.getElementById('passengers');
        const template = document.getElementById('passenger-template');
        const addButton = document.getElementById('add-passenger');
        const flightSelect = document.getElementById('flight_id');
        const allowances = @json($allowances);
        const fares = @json($fares);
        const fees = @json($fees);

        const rows = () => [...list.querySelectorAll('.passenger-row')];

        function formatMoney(cents) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format((cents || 0) / 100);
        }

        function formatKg(kg) {
            return Number.isInteger(kg) ? String(kg) : String(kg);
        }

        function adultCabinFor(row) {
            const adultIndex = row.querySelector('.accompanying-adult').value;
            const adult = rows().find((item) => item.dataset.index === adultIndex);

            return adult?.querySelector('.passenger-cabin')?.value || '';
        }

        function fareCents(cabin, type) {
            return fares.find((item) => item.cabin === cabin && item.type === type)?.cents ?? null;
        }

        function refreshAllowances() {
            rows().forEach((row) => {
                const type = row.querySelector('.passenger-type').value;
                const cabin = type === 'Infant' ? adultCabinFor(row) : row.querySelector('.passenger-cabin').value;
                const allowanceEl = row.querySelector('.allowance-label');
                const fareEl = row.querySelector('.passenger-fare-label');
                const bagPrice = row.querySelector('.extra-bag-price');
                const kgPrice = row.querySelector('.extra-kg-price');
                const base = allowances[cabin];
                const cents = fareCents(cabin, type === 'Infant' ? 'Infant' : type);

                if (allowanceEl) {
                    allowanceEl.textContent = base
                        ? `Allowance ${base.pieces} × ${formatKg(base.kg)} kg`
                        : '';
                }

                if (fareEl) {
                    fareEl.textContent = cents === null ? '' : `${type}: ${formatMoney(cents)}`;
                }

                if (bagPrice) {
                    bagPrice.textContent = fees.extra_bag ? `+${formatMoney(fees.extra_bag)} each` : '';
                }

                if (kgPrice) {
                    kgPrice.textContent = fees.extra_kg ? `+${formatMoney(fees.extra_kg)} / kg` : '';
                }
            });
        }

        function refreshPrice() {
            const groups = new Map();
            let extraBags = 0;
            let extraKg = 0;

            rows().forEach((row) => {
                const type = row.querySelector('.passenger-type').value;
                const cabin = type === 'Infant' ? adultCabinFor(row) : row.querySelector('.passenger-cabin').value;

                if (cabin !== '') {
                    const key = `${cabin}|${type}`;
                    groups.set(key, (groups.get(key) || 0) + 1);
                }

                if (type !== 'Infant') {
                    extraBags += Number(row.querySelector('.passenger-extra-bags').value || 0);
                    extraKg += Number(row.querySelector('.passenger-extra-kg').value || 0);
                }
            });

            const lines = [];
            let total = 0;

            groups.forEach((quantity, key) => {
                const [cabin, type] = key.split('|');
                const unit = fareCents(cabin, type);

                if (unit === null) {
                    return;
                }

                const amount = quantity * unit;
                total += amount;
                lines.push({
                    label: `${cabin} ${type}`,
                    detail: quantity === 1 ? formatMoney(unit) : `${quantity} × ${formatMoney(unit)}`,
                    amount: formatMoney(amount),
                });
            });

            if (extraBags > 0 && fees.extra_bag > 0) {
                const amount = extraBags * fees.extra_bag;
                total += amount;
                lines.push({
                    label: 'Extra bags',
                    detail: `${extraBags} × ${formatMoney(fees.extra_bag)}`,
                    amount: formatMoney(amount),
                });
            }

            if (extraKg > 0 && fees.extra_kg > 0) {
                const amount = extraKg * fees.extra_kg;
                total += amount;
                lines.push({
                    label: 'Extra weight',
                    detail: `${extraKg} kg × ${formatMoney(fees.extra_kg)}`,
                    amount: formatMoney(amount),
                });
            }

            const listEl = document.getElementById('price-lines');
            listEl.replaceChildren();

            if (lines.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'text-gray-500';
                empty.textContent = 'Add passengers to build the quote.';
                listEl.appendChild(empty);
            } else {
                lines.forEach((line) => {
                    const item = document.createElement('li');
                    item.className = 'flex items-start justify-between gap-3';
                    item.innerHTML = `<span><span class="font-medium text-gray-900">${line.label}</span><span class="mt-0.5 block text-xs text-gray-500">${line.detail}</span></span><span class="font-medium">${line.amount}</span>`;
                    listEl.appendChild(item);
                });
            }

            const totalLabel = formatMoney(total);
            document.getElementById('price-total').textContent = totalLabel;
            document.getElementById('pay-button-amount').textContent = totalLabel;
            const cardAmount = document.getElementById('card-form-amount');
            if (cardAmount) {
                cardAmount.textContent = totalLabel;
            }
        }

        function refreshFlightSummary() {
            const option = flightSelect.selectedOptions[0];
            const summary = document.getElementById('summary-flight');

            if (! option || option.value === '') {
                summary.textContent = 'Select a flight first';

                return;
            }

            summary.textContent = option.textContent.trim();
        }

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
                const cabinField = row.querySelector('.cabin-field');
                const cabinSelect = row.querySelector('.passenger-cabin');
                const extrasField = row.querySelector('.extras-field');
                row.querySelector('.accompanying-field').classList.toggle('hidden', type !== 'Infant');
                row.querySelector('.seat-field').classList.toggle('hidden', type === 'Infant');
                cabinField.classList.toggle('hidden', type === 'Infant');
                extrasField.classList.toggle('hidden', type === 'Infant');
                cabinSelect.required = type !== 'Infant';

                if (type === 'Infant') {
                    row.querySelector('.passenger-seat').value = '';
                    row.querySelector('.passenger-extra-bags').value = '0';
                    row.querySelector('.passenger-extra-kg').value = '0';
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
            refreshAllowances();
            refreshPrice();
            paintMap();
        }

        let seatData = [];
        let activeIndex = '0';
        let mapRequest = 0;

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
                hint.textContent = 'Select a flight to load the seat map.';

                return;
            }

            const activeRow = rows().find((row) => row.dataset.index === activeIndex);
            const cabin = activeRow?.querySelector('.passenger-cabin')?.value || '';
            const offered = seatData.filter((seat) => seat.cabin === cabin);

            hint.textContent = cabin === ''
                ? 'Set cabin class, then assign a free seat.'
                : `Assigning ${cabin} seat for passenger ${Number(activeIndex) + 1}. Taken seats blocked.`;

            if (offered.length === 0) {
                hint.textContent = cabin === ''
                    ? 'Set cabin class, then assign a free seat.'
                    : `No ${cabin} seats on this aircraft.`;

                return;
            }

            window.ArcsSeatMap.draw(map, offered, (seat) => {
                const owner = chosen.get(String(seat.id));
                const mine = owner === activeIndex;
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
            if (seat.taken) {
                return;
            }

            const row = rows().find((item) => item.dataset.index === activeIndex);

            if (!row || row.querySelector('.passenger-type').value === 'Infant') {
                return;
            }

            const cabin = row.querySelector('.passenger-cabin').value;

            if (seat.cabin !== cabin) {
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

        function clearSeatIfWrongCabin(row) {
            const input = row.querySelector('.passenger-seat');
            const cabin = row.querySelector('.passenger-cabin').value;
            const seat = seatData.find((item) => String(item.id) === input.value);

            if (input.value !== '' && (!seat || seat.cabin !== cabin)) {
                input.value = '';
            }
        }

        async function loadMap() {
            const request = ++mapRequest;
            const flightId = flightSelect.value;
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

                if (input.value !== '' && (!seat || seat.taken || seat.cabin !== row.querySelector('.passenger-cabin').value)) {
                    input.value = '';
                }
            });

            paintMap();
        }

        flightSelect.addEventListener('change', () => {
            rows().forEach((row) => {
                row.querySelector('.passenger-seat').value = '';
            });
            refreshFlightSummary();
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

        list.addEventListener('input', () => {
            refreshAdults();
            refreshAllowances();
            refreshPrice();
        });
        list.addEventListener('change', (event) => {
            const row = event.target.closest('.passenger-row');

            if (row && event.target.classList.contains('passenger-cabin')) {
                clearSeatIfWrongCabin(row);
            }

            refreshAdults();
            refreshAllowances();
            refreshPrice();
            paintMap();
        });

        reindex();
        refreshFlightSummary();
        loadMap();
    </script>
</x-app-layout>
