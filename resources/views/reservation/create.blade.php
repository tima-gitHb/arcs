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

@php
    $bookingStep = 0;
    if ($errors->has('card_number') || $errors->has('card_expiry') || $errors->has('card_cvc')) {
        $bookingStep = 4;
    } elseif ($errors->has('seat') || $errors->has('passengers.*.seats') || $errors->has('passengers.*.seat_id')) {
        $bookingStep = 2;
    } elseif ($errors->has('passengers') || $errors->has('passengers.*') || $errors->has('contact_email')) {
        $bookingStep = 1;
    } elseif ($errors->has('outbound_flight_ids') || $errors->has('return_flight_ids') || $errors->has('journey_type')) {
        $bookingStep = 0;
    }
@endphp

<x-app-layout>
    <x-page-header title="New reservation" subtitle="Desk / phone booking: trip, passengers, seats, extras, then card. PNR is created only after approval.">
        <a href="{{ route('reservation.search') }}" class="arcs-btn-secondary">Find reservation</a>
    </x-page-header>

    <div class="mb-6">
        <x-todays-flights :role="'reservation'" />
    </div>

    <div class="mb-6" x-data="{ step: {{ $bookingStep }} }" x-cloak>
        <x-step-bar class="mb-4" :steps="['Trip', 'Passengers', 'Seats', 'Extras', 'Payment']" :current="$bookingStep" />
        <p class="mb-4 text-xs text-gray-500">Steps are sections of one form — use Continue / Back, then charge on Payment. A validation error opens the step with the problem.</p>

    <form method="POST" action="{{ route('reservation.store') }}" autocomplete="off" id="booking-form">
        @csrf

        @if (! $pricesReady)
            <div class="mb-4 rounded-[14px] border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
                Fares are not published. Ask a manager to set prices (M7), then refresh.
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-[14px] border px-4 py-3 text-sm" style="border-color: #F5C2C0; color: var(--arcs-error); background: #FCE8E6;" role="alert">
                @if ($errors->has('card_number') && ! $errors->has('outbound_flight_ids') && ! $errors->has('passengers'))
                    {{ $errors->first('card_number') }}
                @elseif ($errors->has('outbound_flight_ids'))
                    {{ $errors->first('outbound_flight_ids') }}
                @else
                    Fix the highlighted fields, then charge again.
                @endif
            </div>
        @endif

        <div class="rounded-[14px] border bg-white p-5 sm:p-6 shadow-sm" style="border-color: var(--arcs-border);" x-show="step === 0">
            <p class="text-sm text-gray-600">
                Desk / phone booking: choose one-way, connection or round trip, pick the flights, assign a seat on each, read the quote, then take the card. PNR is created only after approval.
            </p>

            <fieldset class="mt-6">
                <legend class="text-sm font-medium text-gray-700">Journey type</legend>
                {{-- Segmented control (docs/ui/Reservation.png); same radio names/values as before. --}}
                <div class="mt-2 inline-flex flex-wrap rounded-lg border p-1 text-sm" style="border-color: var(--arcs-input-border); background: var(--arcs-page);">
                    @foreach (['one_way' => 'One-way', 'connection' => 'With connection', 'round_trip' => 'Round trip'] as $value => $label)
                        <label @class([
                            'inline-flex min-h-[40px] cursor-pointer items-center rounded-md px-3 py-1.5 font-medium',
                            'bg-[var(--arcs-bar)] text-white shadow-sm' => $journeyType === $value,
                            'text-gray-700 hover:bg-white' => $journeyType !== $value,
                        ])>
                            <input
                                type="radio"
                                name="journey_type"
                                value="{{ $value }}"
                                class="journey-type sr-only"
                                @checked($journeyType === $value)
                            >
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('journey_type')" class="mt-2" />
            </fieldset>

            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <div>
                    <x-input-label value="Outbound" />
                    <div id="outbound-legs" class="mt-2 space-y-2"></div>
                    <button type="button" id="add-outbound-leg" class="mt-2 hidden text-sm font-medium text-navy hover:underline">
                        Add connecting flight
                    </button>
                    <x-input-error :messages="$errors->get('outbound_flight_ids')" class="mt-2" />
                    <x-input-error :messages="$errors->get('outbound_flight_ids.*')" class="mt-2" />
                </div>
                <div id="return-panel" class="{{ $journeyType === 'round_trip' ? '' : 'hidden' }}">
                    <x-input-label value="Return" />
                    <div id="return-legs" class="mt-2 space-y-2"></div>
                    <button type="button" id="add-return-leg" class="mt-2 text-sm font-medium text-navy hover:underline">
                        Add return connection
                    </button>
                    <x-input-error :messages="$errors->get('return_flight_ids')" class="mt-2" />
                    <x-input-error :messages="$errors->get('return_flight_ids.*')" class="mt-2" />
                </div>
            </div>

            <p class="mt-3 text-sm text-gray-500">
                After the first leg, only connecting flights that leave after the minimum connection time are listed.
                Same flight cannot be picked twice. Not listed: Closed, Departed, Cancelled, past Scheduled.
            </p>
            <p id="connection-hint" class="mt-1 hidden text-sm text-amber-800"></p>
            @if ($flights->isEmpty())
                <p class="mt-2 text-sm text-gray-500">No bookable flights right now.</p>
            @endif
            <div class="mt-6 flex justify-end">
                <button type="button" class="arcs-btn-primary" @click="step = 1">Continue</button>
            </div>
        </div>

        <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-6">
                <div id="passengers" class="space-y-4" x-show="step === 1 || step === 2 || step === 3">
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
                    class="arcs-btn-secondary"
                    x-show="step === 1"
                >
                    Add passenger
                </button>

                <div class="flex justify-between gap-2" x-show="step === 1">
                    <button type="button" class="arcs-btn-secondary" @click="step = 0">Back</button>
                    <button type="button" class="arcs-btn-primary" @click="step = 2">Continue</button>
                </div>

                <div class="rounded-[14px] border bg-white p-5 sm:p-6 shadow-sm" style="border-color: var(--arcs-border);" x-show="step === 1 || step === 4">
                    <h2 class="text-lg font-semibold text-gray-900">Caller contact</h2>
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

                <div x-show="step === 3" class="rounded-[14px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                    <h2 class="text-lg font-semibold text-gray-900">Extras</h2>
                    <p class="mt-1 text-sm text-gray-600">Extra bags and kilograms are set on each passenger row. Review the quote, then continue to payment.</p>
                    <div class="mt-4 flex justify-between gap-2">
                        <button type="button" class="arcs-btn-secondary" @click="step = 2">Back</button>
                        <button type="button" class="arcs-btn-primary" @click="step = 4">Continue</button>
                    </div>
                </div>

                <div x-show="step === 4">
                    <x-card-form amount-label="$0.00" />

                    <div class="mt-4">
                        <div class="mb-3 flex justify-between gap-2">
                            <button type="button" class="arcs-btn-secondary" @click="step = 3">Back</button>
                        </div>
                        <button
                            type="submit"
                            class="arcs-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            @disabled($flights->isEmpty() || ! $pricesReady)
                        >
                            Charge <span id="pay-button-amount">$0.00</span> and save PNR
                        </button>
                        <p class="mt-2 text-sm text-gray-500">Declined card → no PNR created.</p>
                    </div>
                </div>
            </div>

            <aside class="space-y-4" x-show="step === 2 || step === 3 || step === 4">
                <div class="rounded-[14px] border bg-white p-4 shadow-sm" style="border-color: var(--arcs-border);" x-show="step === 2">
                    <x-input-label for="active-seat-flight" value="Seat map flight" />
                    <select
                        id="active-seat-flight"
                        class="arcs-input mt-1"
                    >
                        <option value="">Select flights first…</option>
                    </select>
                    <div class="mt-4 flex justify-between gap-2">
                        <button type="button" class="arcs-btn-secondary" @click="step = 1">Back</button>
                        <button type="button" class="arcs-btn-primary" @click="step = 3">Continue</button>
                    </div>
                </div>

                <div x-show="step === 2">
                    <x-seat-map-dock hint="Pick the flight above → select passenger → assign a free seat in that cabin on that flight.">
                        <x-input-error :messages="$errors->get('seat')" class="mt-3" />
                    </x-seat-map-dock>
                </div>

                <div class="rounded-[14px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                    <h2 class="text-lg font-semibold text-gray-900">Fare quote</h2>
                    <p class="mt-1 text-xs text-gray-500">
                        Each segment uses its route fare (M14), else the default. Extras once. Read the total back before charging.
                    </p>
                    <p id="summary-flight" class="mt-2 text-sm font-medium text-gray-700">No flight selected</p>

                    <ul id="price-lines" class="mt-4 space-y-3 border-t border-gray-100 pt-4 text-sm text-gray-800"></ul>

                    <div class="mt-4 flex items-end justify-between border-t border-gray-100 pt-4">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Amount to charge</p>
                            <p class="text-2xl font-semibold text-navy"><span id="price-total">$0.00</span></p>
                            <p class="text-xs text-gray-500">USD · sum of segments + prepaid extras</p>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </form>
    </div>

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
        const outboundBox = document.getElementById('outbound-legs');
        const returnBox = document.getElementById('return-legs');
        const returnPanel = document.getElementById('return-panel');
        const addOutboundBtn = document.getElementById('add-outbound-leg');
        const addReturnBtn = document.getElementById('add-return-leg');
        const activeFlightSelect = document.getElementById('active-seat-flight');
        const allowances = @json($allowances);
        const fares = @json($fares);
        const fees = @json($fees);
        const allFlights = @json($flightPayload);
        const connectionMinutes = @json($connectionMinutes);
        let initialOutbound = @json($outboundFlightIds);
        let initialReturn = @json($returnFlightIds);

        const rows = () => [...list.querySelectorAll('.passenger-row')];
        const flightById = Object.fromEntries(allFlights.map((flight) => [String(flight.id), flight]));

        function journeyType() {
            return document.querySelector('input[name="journey_type"]:checked')?.value || 'one_way';
        }

        function mctAt(code) {
            return Number(connectionMinutes[code] ?? 60);
        }

        function gapMinutes(previous, next) {
            if (! previous?.arrives_at || ! next?.departure_at) {
                return null;
            }

            return Math.floor((Date.parse(next.departure_at) - Date.parse(previous.arrives_at)) / 60000);
        }

        function previousFlightFor(direction, index) {
            if (direction === 'outbound') {
                if (index === 0) {
                    return null;
                }

                return flightById[selectedFlightIds(outboundBox)[index - 1]] || null;
            }

            if (index === 0) {
                const outbound = selectedFlightIds(outboundBox);

                return flightById[outbound[outbound.length - 1]] || null;
            }

            return flightById[selectedFlightIds(returnBox)[index - 1]] || null;
        }

        function takenFlightIds(exceptSelect = null) {
            return new Set(
                [...outboundBox.querySelectorAll('.journey-flight'), ...returnBox.querySelectorAll('.journey-flight')]
                    .filter((select) => select !== exceptSelect)
                    .map((select) => select.value)
                    .filter((value) => value !== ''),
            );
        }

        function eligibleFlights(direction, index, currentId = '', exceptSelect = null) {
            const previous = previousFlightFor(direction, index);
            const taken = takenFlightIds(exceptSelect);

            return allFlights.filter((flight) => {
                const id = String(flight.id);

                if (id === String(currentId)) {
                    return true;
                }

                if (taken.has(id)) {
                    return false;
                }

                if (! previous) {
                    return true;
                }

                if (flight.origin !== previous.destination) {
                    return false;
                }

                if (! previous.arrives_at) {
                    return false;
                }

                const gap = gapMinutes(previous, flight);

                return gap !== null && gap >= mctAt(previous.destination);
            });
        }

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

        function fareLookup(cabin, type, origin = null, destination = null) {
            const route = fares.find((item) =>
                item.cabin === cabin
                && item.type === type
                && item.origin === origin
                && item.destination === destination
            );
            if (route) {
                return { cents: route.cents, source: 'route' };
            }

            const fallback = fares.find((item) =>
                item.cabin === cabin
                && item.type === type
                && item.origin === null
                && item.destination === null
            );

            return fallback ? { cents: fallback.cents, source: 'default' } : null;
        }

        function fareCents(cabin, type, origin = null, destination = null) {
            return fareLookup(cabin, type, origin, destination)?.cents ?? null;
        }

        function selectedFlightIds(box) {
            return [...box.querySelectorAll('.journey-flight')]
                .map((select) => select.value)
                .filter((value) => value !== '');
        }

        function selectedFlights() {
            const outbound = selectedFlightIds(outboundBox).map((id) => flightById[id]).filter(Boolean);
            const ret = journeyType() === 'round_trip'
                ? selectedFlightIds(returnBox).map((id) => flightById[id]).filter(Boolean)
                : [];

            return [...outbound, ...ret];
        }

        function fillFlightSelect(select, direction, index, selected = '') {
            select.dataset.direction = direction;
            select.dataset.legIndex = String(index);

            const current = selected !== '' ? String(selected) : (select.value || '');
            const offered = eligibleFlights(direction, index, current, select);

            select.replaceChildren();
            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = previousFlightFor(direction, index)
                ? 'Select connecting flight…'
                : 'Select flight…';
            select.appendChild(blank);

            if (offered.length === 0) {
                const empty = document.createElement('option');
                empty.value = '';
                empty.disabled = true;
                empty.textContent = 'No connecting flight available';
                select.appendChild(empty);
            }

            offered.forEach((flight) => {
                const option = document.createElement('option');
                option.value = String(flight.id);
                option.textContent = flight.label;
                option.selected = current === String(flight.id);
                select.appendChild(option);
            });

            if (current && ! offered.some((flight) => String(flight.id) === current)) {
                select.value = '';
            }
        }

        function legLabel(direction, index) {
            const nth = index === 0 ? '1st' : (index === 1 ? '2nd' : '3rd');

            if (direction === 'outbound') {
                return index === 0 ? '1st outbound' : `${nth} outbound (connection)`;
            }

            return index === 0 ? 'Return' : `${nth} return (connection)`;
        }

        function makeLegSelect(name, direction, index, selected = '') {
            const wrap = document.createElement('div');
            wrap.className = 'space-y-1';

            const label = document.createElement('p');
            label.className = 'text-xs font-medium uppercase tracking-wide text-gray-500';
            label.textContent = legLabel(direction, index);
            wrap.appendChild(label);

            const select = document.createElement('select');
            select.name = name;
            select.className = 'journey-flight mt-0 block w-full rounded-md border-gray-300 shadow-sm focus:border-navy focus:ring-navy';
            fillFlightSelect(select, direction, index, selected);
            wrap.appendChild(select);

            return wrap;
        }

        function refreshLegOptions() {
            [...outboundBox.querySelectorAll('.journey-flight')].forEach((select, index) => {
                fillFlightSelect(select, 'outbound', index, select.value);
            });
            [...returnBox.querySelectorAll('.journey-flight')].forEach((select, index) => {
                fillFlightSelect(select, 'return', index, select.value);
            });
            updateConnectionHint();
        }

        function updateConnectionHint() {
            const hint = document.getElementById('connection-hint');
            const type = journeyType();
            const outbound = selectedFlightIds(outboundBox).map((id) => flightById[id]).filter(Boolean);
            const messages = [];

            if (type === 'connection' && outbound.length >= 1) {
                const last = outbound[outbound.length - 1];
                const nextSelect = outboundBox.querySelectorAll('.journey-flight')[outbound.length];
                if (nextSelect && nextSelect.value === '') {
                    messages.push(
                        `Next leg must depart ${last.destination} at least ${mctAt(last.destination)} min after landing.`,
                    );
                }
            }

            if (type === 'round_trip' && outbound.length >= 1) {
                const last = outbound[outbound.length - 1];
                const returnSelect = returnBox.querySelector('.journey-flight');
                if (returnSelect && returnSelect.value === '') {
                    messages.push(
                        `Return must start at ${last.destination} and leave after the outbound lands (min ${mctAt(last.destination)} min).`,
                    );
                }
            }

            if (messages.length === 0) {
                hint.classList.add('hidden');
                hint.textContent = '';

                return;
            }

            hint.classList.remove('hidden');
            hint.textContent = messages.join(' ');
        }

        function rebuildLegs() {
            const type = journeyType();
            const outboundWanted = type === 'connection'
                ? Math.max(2, outboundBox.querySelectorAll('.journey-flight').length || 2)
                : 1;
            const returnWanted = type === 'round_trip'
                ? Math.max(1, returnBox.querySelectorAll('.journey-flight').length || 1)
                : 0;

            const keepOutbound = selectedFlightIds(outboundBox);
            const keepReturn = selectedFlightIds(returnBox);

            outboundBox.replaceChildren();
            for (let i = 0; i < outboundWanted; i++) {
                outboundBox.appendChild(makeLegSelect(
                    'outbound_flight_ids[]',
                    'outbound',
                    i,
                    keepOutbound[i] ?? initialOutbound[i] ?? '',
                ));
            }

            returnPanel.classList.toggle('hidden', type !== 'round_trip');
            addOutboundBtn.classList.toggle('hidden', type !== 'connection');
            addReturnBtn.classList.toggle('hidden', type !== 'round_trip');

            returnBox.replaceChildren();
            for (let i = 0; i < returnWanted; i++) {
                returnBox.appendChild(makeLegSelect(
                    'return_flight_ids[]',
                    'return',
                    i,
                    keepReturn[i] ?? initialReturn[i] ?? '',
                ));
            }

            initialOutbound = [];
            initialReturn = [];

            refreshLegOptions();
            syncSeatInputs();
            refreshActiveFlightOptions();
            refreshFlightSummary();
            refreshAllowances();
            refreshPrice();
            loadMap();
        }

        function refreshActiveFlightOptions() {
            const flights = selectedFlights();
            const current = activeFlightSelect.value;
            activeFlightSelect.replaceChildren();

            if (flights.length === 0) {
                const blank = document.createElement('option');
                blank.value = '';
                blank.textContent = 'Select flights first…';
                activeFlightSelect.appendChild(blank);

                return;
            }

            flights.forEach((flight, index) => {
                const option = document.createElement('option');
                option.value = String(flight.id);
                option.textContent = `${index + 1}. ${flight.flight_no} · ${flight.origin} → ${flight.destination}`;
                activeFlightSelect.appendChild(option);
            });

            if (flights.some((flight) => String(flight.id) === current)) {
                activeFlightSelect.value = current;
            }
        }

        function syncSeatInputs() {
            const flights = selectedFlights();

            rows().forEach((row) => {
                const box = row.querySelector('.seat-assignments');
                const previous = new Map(
                    [...box.querySelectorAll('.seat-leg')].map((leg) => [
                        leg.dataset.flightId || 'legacy',
                        leg.querySelector('.passenger-seat')?.value || '',
                    ]),
                );

                box.replaceChildren();

                if (flights.length === 0) {
                    const leg = document.createElement('div');
                    leg.className = 'seat-leg flex flex-wrap items-center gap-2';
                    leg.dataset.flightId = '';
                    leg.innerHTML = `
                        <span class="seat-leg-label text-sm text-gray-600">Seat</span>
                        <span class="seat-label text-sm font-semibold text-navy">Not chosen</span>
                        <input type="hidden" class="passenger-seat" name="passengers[${row.dataset.index}][seat_id]" value="">
                        <button type="button" class="pick-seat text-sm font-medium text-navy hover:underline">Assign on map</button>
                    `;
                    box.appendChild(leg);

                    return;
                }

                flights.forEach((flight) => {
                    const value = previous.get(String(flight.id))
                        || (flights.length === 1 ? (previous.get('legacy') || previous.get('') || '') : '');
                    const leg = document.createElement('div');
                    leg.className = 'seat-leg flex flex-wrap items-center gap-2';
                    leg.dataset.flightId = String(flight.id);
                    leg.innerHTML = `
                        <span class="seat-leg-label text-sm text-gray-600">${flight.flight_no}</span>
                        <span class="seat-label text-sm font-semibold text-navy">Not chosen</span>
                        <input type="hidden" class="passenger-seat" name="passengers[${row.dataset.index}][seats][${flight.id}]" value="${value}">
                        <button type="button" class="pick-seat text-sm font-medium text-navy hover:underline">Assign on map</button>
                    `;
                    box.appendChild(leg);
                });
            });
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
                const flights = selectedFlights();
                let cents = 0;
                let priced = flights.length > 0;

                flights.forEach((flight) => {
                    const unit = fareCents(cabin, type === 'Infant' ? 'Infant' : type, flight.origin, flight.destination);
                    if (unit === null) {
                        priced = false;
                    } else {
                        cents += unit;
                    }
                });

                if (allowanceEl) {
                    allowanceEl.textContent = base
                        ? `Allowance ${base.pieces} × ${formatKg(base.kg)} kg`
                        : '';
                }

                if (fareEl) {
                    fareEl.textContent = ! priced || flights.length === 0
                        ? ''
                        : `${type}: ${formatMoney(cents)}${flights.length > 1 ? ' (journey)' : ''}`;
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
            const flights = selectedFlights();
            const segments = [];
            const extras = [];
            let total = 0;
            let extraBags = 0;
            let extraKg = 0;

            flights.forEach((flight) => {
                const groups = new Map();
                const segmentLines = [];
                let segmentTotal = 0;

                rows().forEach((row) => {
                    const type = row.querySelector('.passenger-type').value;
                    const cabin = type === 'Infant' ? adultCabinFor(row) : row.querySelector('.passenger-cabin').value;

                    if (cabin === '') {
                        return;
                    }

                    const key = `${cabin}|${type}`;
                    groups.set(key, (groups.get(key) || 0) + 1);
                });

                groups.forEach((quantity, key) => {
                    const [cabin, type] = key.split('|');
                    const fare = fareLookup(cabin, type, flight.origin, flight.destination);

                    if (fare === null) {
                        return;
                    }

                    const amount = quantity * fare.cents;
                    segmentTotal += amount;
                    total += amount;
                    segmentLines.push({
                        label: `${cabin} ${type}`,
                        detail: `${quantity === 1 ? formatMoney(fare.cents) : `${quantity} × ${formatMoney(fare.cents)}`} · ${fare.source === 'route' ? 'route fare' : 'default fare'}`,
                        amount: formatMoney(amount),
                    });
                });

                if (segmentLines.length > 0) {
                    segments.push({
                        title: `${flight.origin}→${flight.destination} · ${flight.flight_no}`,
                        total: formatMoney(segmentTotal),
                        lines: segmentLines,
                    });
                }
            });

            rows().forEach((row) => {
                if (row.querySelector('.passenger-type').value === 'Infant') {
                    return;
                }

                extraBags += Number(row.querySelector('.passenger-extra-bags').value || 0);
                extraKg += Number(row.querySelector('.passenger-extra-kg').value || 0);
            });

            if (extraBags > 0 && fees.extra_bag > 0) {
                const amount = extraBags * fees.extra_bag;
                total += amount;
                extras.push({
                    label: 'Extra bags',
                    detail: `${extraBags} × ${formatMoney(fees.extra_bag)}`,
                    amount: formatMoney(amount),
                });
            }

            if (extraKg > 0 && fees.extra_kg > 0) {
                const amount = extraKg * fees.extra_kg;
                total += amount;
                extras.push({
                    label: 'Extra weight',
                    detail: `${extraKg} kg × ${formatMoney(fees.extra_kg)}`,
                    amount: formatMoney(amount),
                });
            }

            const listEl = document.getElementById('price-lines');
            listEl.replaceChildren();

            if (segments.length === 0 && extras.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'text-gray-500';
                empty.textContent = 'Pick flights and passengers to build the quote.';
                listEl.appendChild(empty);
            } else {
                segments.forEach((segment) => {
                    const header = document.createElement('li');
                    header.className = 'flex items-baseline justify-between gap-3 border-b border-gray-100 pb-1 text-xs font-semibold uppercase tracking-wide text-navy';
                    header.innerHTML = `<span>${segment.title}</span><span>${segment.total}</span>`;
                    listEl.appendChild(header);

                    segment.lines.forEach((line) => {
                        const item = document.createElement('li');
                        item.className = 'flex items-start justify-between gap-3 pl-1';
                        item.innerHTML = `<span><span class="font-medium text-gray-900">${line.label}</span><span class="mt-0.5 block text-xs text-gray-500">${line.detail}</span></span><span class="font-medium">${line.amount}</span>`;
                        listEl.appendChild(item);
                    });
                });

                extras.forEach((line) => {
                    const item = document.createElement('li');
                    item.className = 'flex items-start justify-between gap-3 border-t border-dashed border-gray-100 pt-2';
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
            const summary = document.getElementById('summary-flight');
            const flights = selectedFlights();

            if (flights.length === 0) {
                summary.textContent = 'Select flights first';

                return;
            }

            summary.textContent = flights
                .map((flight) => `${flight.flight_no} ${flight.origin}→${flight.destination}`)
                .join(' · ');
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
                    row.querySelectorAll('.passenger-seat').forEach((input) => {
                        input.value = '';
                    });
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

            syncSeatInputs();
            refreshAdults();
            refreshAllowances();
            refreshPrice();
            paintMap();
        }

        let seatData = [];
        let activeIndex = '0';
        let mapRequest = 0;

        function activeFlightId() {
            return activeFlightSelect.value;
        }

        function seatInputFor(row, flightId = activeFlightId()) {
            return row.querySelector(`.seat-leg[data-flight-id="${flightId}"] .passenger-seat`)
                || row.querySelector('.passenger-seat');
        }

        function chosenSeats() {
            const chosen = new Map();
            const flightId = activeFlightId();

            rows().forEach((row) => {
                const seatId = seatInputFor(row, flightId)?.value;

                if (seatId) {
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
            const flightId = activeFlightId();

            rows().forEach((row) => {
                row.querySelectorAll('.seat-leg').forEach((leg) => {
                    const input = leg.querySelector('.passenger-seat');
                    const label = leg.querySelector('.seat-label');
                    const seat = seatData.find((item) => String(item.id) === input.value && leg.dataset.flightId === flightId);
                    const known = seatData.find((item) => String(item.id) === input.value);
                    label.textContent = input.value === ''
                        ? 'Not chosen'
                        : (leg.dataset.flightId === flightId && known ? known.seat_no : (input.value ? 'Chosen' : 'Not chosen'));
                });
                row.classList.toggle('ring-2', row.dataset.index === activeIndex);
                row.classList.toggle('ring-navy', row.dataset.index === activeIndex);
            });

            const map = document.getElementById('seat-map');
            const hint = document.getElementById('seat-map-hint');
            map.replaceChildren();

            if (! flightId || seatData.length === 0) {
                hint.textContent = 'Select flights, then load a seat map.';

                return;
            }

            const activeRow = rows().find((row) => row.dataset.index === activeIndex);
            const cabin = activeRow?.querySelector('.passenger-cabin')?.value || '';
            const offered = seatData.filter((seat) => seat.cabin === cabin);
            const flight = flightById[flightId];

            hint.textContent = cabin === ''
                ? 'Set cabin class, then assign a free seat.'
                : `Assigning ${cabin} on ${flight?.flight_no || 'flight'} for passenger ${Number(activeIndex) + 1}.`;

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

            const input = seatInputFor(row);

            if (! input) {
                return;
            }

            const owner = chosenSeats().get(String(seat.id));

            if (owner && owner !== activeIndex) {
                return;
            }

            input.value = input.value === String(seat.id) ? '' : String(seat.id);
            paintMap();
        }

        function clearSeatIfWrongCabin(row) {
            const cabin = row.querySelector('.passenger-cabin').value;
            const input = seatInputFor(row);
            const seat = seatData.find((item) => String(item.id) === input?.value);

            if (input && input.value !== '' && (!seat || seat.cabin !== cabin)) {
                input.value = '';
            }
        }

        async function loadMap() {
            const request = ++mapRequest;
            const flightId = activeFlightId();
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
                const input = seatInputFor(row, flightId);
                const seat = seatData.find((item) => String(item.id) === input?.value);

                if (input && input.value !== '' && (!seat || seat.taken || seat.cabin !== row.querySelector('.passenger-cabin').value)) {
                    input.value = '';
                }
            });

            paintMap();
        }

        document.querySelectorAll('input[name="journey_type"]').forEach((input) => {
            input.addEventListener('change', rebuildLegs);
        });

        outboundBox.addEventListener('change', () => {
            refreshLegOptions();
            syncSeatInputs();
            refreshActiveFlightOptions();
            refreshFlightSummary();
            refreshAllowances();
            refreshPrice();
            loadMap();
        });

        returnBox.addEventListener('change', () => {
            refreshLegOptions();
            syncSeatInputs();
            refreshActiveFlightOptions();
            refreshFlightSummary();
            refreshAllowances();
            refreshPrice();
            loadMap();
        });

        addOutboundBtn.addEventListener('click', () => {
            if (outboundBox.querySelectorAll('.journey-flight').length >= 3) {
                return;
            }

            const index = outboundBox.querySelectorAll('.journey-flight').length;
            outboundBox.appendChild(makeLegSelect('outbound_flight_ids[]', 'outbound', index));
            refreshLegOptions();
            updateConnectionHint();
        });

        addReturnBtn.addEventListener('click', () => {
            if (returnBox.querySelectorAll('.journey-flight').length >= 3) {
                return;
            }

            const index = returnBox.querySelectorAll('.journey-flight').length;
            returnBox.appendChild(makeLegSelect('return_flight_ids[]', 'return', index));
            refreshLegOptions();
            updateConnectionHint();
        });

        activeFlightSelect.addEventListener('change', loadMap);

        addButton.addEventListener('click', () => {
            list.appendChild(template.content.cloneNode(true));
            reindex();
            list.lastElementChild.querySelector('.passenger-first').focus();
        });

        list.addEventListener('click', (event) => {
            const pick = event.target.closest('.pick-seat');

            if (pick) {
                const row = pick.closest('.passenger-row');
                const leg = pick.closest('.seat-leg');

                if (row.querySelector('.passenger-type').value !== 'Infant') {
                    activeIndex = row.dataset.index;
                    if (leg?.dataset.flightId) {
                        activeFlightSelect.value = leg.dataset.flightId;
                        loadMap();
                    } else {
                        paintMap();
                    }
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

        rebuildLegs();
        reindex();
    </script>
</x-app-layout>
