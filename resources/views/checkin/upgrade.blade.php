<x-app-layout>
    <x-slot name="aside">
        <x-seat-map-dock hint="Pick a free seat in the selected cabin." :data-seats="json_encode($seatMap)" />
    </x-slot>

    <x-page-header title="Upgrade cabin" :subtitle="$passenger->first_name.' '.$passenger->last_name.' · now '.$from->label().' · '.$flight->flight_no">
        <a href="{{ route('checkin.passengers.show', [$passenger, 'flight' => $flight->id]) }}" class="arcs-btn-secondary">← Back</a>
    </x-page-header>

    <form method="POST" action="{{ route('checkin.passengers.upgrade.store', $passenger) }}" autocomplete="off" id="upgrade-form" class="space-y-4">
        @csrf
        <input type="hidden" name="flight_id" value="{{ $flight->id }}">

        @if (($journeyFlights ?? collect())->count() > 1)
            <x-panel>
                <p class="text-sm font-medium text-gray-900">Flight to upgrade</p>
                <ul class="mt-2 flex flex-wrap gap-2 text-sm">
                    @foreach ($journeyFlights as $leg)
                        <li>
                            <a href="{{ route('checkin.passengers.upgrade', [$passenger, 'flight' => $leg->id]) }}"
                               class="inline-flex min-h-[44px] items-center rounded-lg border px-3 py-1.5"
                               @style([
                                   'background: var(--arcs-accent)' => $leg->is($flight),
                                   'color: var(--arcs-on-accent)' => $leg->is($flight),
                                   'border-color: var(--arcs-accent)' => $leg->is($flight),
                                   'border-color: var(--arcs-border)' => ! $leg->is($flight),
                                   'color: var(--arcs-accent-text)' => ! $leg->is($flight),
                               ])>
                                {{ $leg->flight_no }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-panel>
        @endif

        <x-panel>
            <h2 class="text-base font-semibold text-gray-900">New class</h2>
            <p class="mt-1 text-sm text-gray-600">Only a higher class. Price is the fare difference on {{ $flight->flight_no }}.</p>
            <div class="mt-4 space-y-2">
                @foreach ($options as $cabin)
                    <label class="flex cursor-pointer items-center justify-between gap-3 rounded-lg border px-4 py-3" style="border-color: var(--arcs-border);">
                        <span class="flex items-center gap-3">
                            <input type="radio" name="cabin_class" value="{{ $cabin->value }}"
                                   data-price="{{ $prices[$cabin->value] }}"
                                   @checked($loop->first) required
                                   class="upgrade-cabin">
                            <span class="font-medium">{{ $cabin->label() }}</span>
                        </span>
                        <span class="font-semibold" style="color: var(--arcs-accent-text);">{{ \App\Support\Money::format($prices[$cabin->value]) }}</span>
                    </label>
                @endforeach
            </div>
            <p class="mt-3 text-sm text-gray-700">
                Amount to charge:
                <span id="upgrade-amount" class="font-semibold" style="color: var(--arcs-accent-text);">{{ \App\Support\Money::format($prices[$options[0]->value]) }}</span>
            </p>
        </x-panel>

        <input type="hidden" name="seat_id" id="upgrade-seat-id" required>

        <x-card-form :amount-cents="$prices[$options[0]->value]" id-prefix="upgrade" title="Pay upgrade" />

        <button type="submit" class="arcs-btn-primary">
            Charge and upgrade
        </button>
    </form>

    <script type="module">
        const seats = @json($seatMap);
        const seatInput = document.getElementById('upgrade-seat-id');
        const amountLabel = document.getElementById('upgrade-amount');
        const cardAmount = document.getElementById('upgrade-card-form-amount');

        function selectedCabin() {
            return document.querySelector('.upgrade-cabin:checked')?.value || '';
        }

        function paint() {
            const cabin = selectedCabin();
            const map = document.getElementById('seat-map');
            const hint = document.getElementById('seat-map-hint');
            hint.textContent = `Free ${cabin} seats. Whole cabin shown · other cabins not allowed.`;

            window.ArcsSeatMap.draw(map, seats, (seat) => {
                const mine = seatInput.value === String(seat.id);

                if (mine) {
                    return { style: 'mine', disabled: false, onPick: (s) => {
                        seatInput.value = '';
                        paint();
                    }};
                }

                if (seat.taken) {
                    return { style: 'taken', disabled: true, title: `${seat.seat_no} · taken` };
                }

                if (cabin !== '' && seat.cabin !== cabin) {
                    return {
                        style: 'blocked',
                        blocked: true,
                        disabled: true,
                        label: '',
                        title: `${seat.seat_no} · ${seat.cabin} · not allowed`,
                    };
                }

                return {
                    style: window.ArcsSeatMap.cabinStyle(seat),
                    disabled: false,
                    onPick: (s) => {
                        seatInput.value = String(s.id);
                        paint();
                    },
                };
            });
        }

        document.querySelectorAll('.upgrade-cabin').forEach((input) => {
            input.addEventListener('change', () => {
                const cents = Number(input.dataset.price || 0);
                const label = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
                amountLabel.textContent = label;
                if (cardAmount) cardAmount.textContent = label;
                seatInput.value = '';
                paint();
            });
        });

        paint();
    </script>
</x-app-layout>
