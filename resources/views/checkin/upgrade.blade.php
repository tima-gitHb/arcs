<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-navy">Upgrade cabin</h1>
                <p class="mt-1 text-sm text-gray-600">
                    {{ $passenger->first_name }} {{ $passenger->last_name }}
                    · now {{ $from->label() }}
                    · {{ $flight->flight_no }}
                </p>
            </div>
            <a href="{{ route('checkin.passengers.show', $passenger) }}" class="text-sm font-medium text-navy hover:underline">← Back</a>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('checkin.passengers.upgrade.store', $passenger) }}" autocomplete="off" id="upgrade-form"
          class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        @csrf

        <div class="space-y-4">
            <div class="rounded-lg bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-navy">New class</h2>
                <p class="mt-1 text-sm text-gray-600">Only a higher class. Price is the fare difference.</p>
                <div class="mt-4 space-y-2">
                    @foreach ($options as $cabin)
                        <label class="flex cursor-pointer items-center justify-between gap-3 rounded border border-gray-200 px-4 py-3 hover:border-navy">
                            <span class="flex items-center gap-3">
                                <input type="radio" name="cabin_class" value="{{ $cabin->value }}"
                                       data-price="{{ $prices[$cabin->value] }}"
                                       @checked($loop->first) required
                                       class="upgrade-cabin">
                                <span class="font-medium">{{ $cabin->label() }}</span>
                            </span>
                            <span class="font-semibold text-navy">{{ \App\Support\Money::format($prices[$cabin->value]) }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-3 text-sm text-gray-700">
                    Amount to charge:
                    <span id="upgrade-amount" class="font-semibold text-navy">{{ \App\Support\Money::format($prices[$options[0]->value]) }}</span>
                </p>
            </div>

            <input type="hidden" name="seat_id" id="upgrade-seat-id" required>

            <x-card-form :amount-cents="$prices[$options[0]->value]" id-prefix="upgrade" title="Pay upgrade" />

            <button type="submit" class="rounded bg-navy px-5 py-2.5 text-sm font-semibold text-white hover:bg-navy/90">
                Charge and upgrade
            </button>
        </div>

        <x-seat-map hint="Pick a free seat in the selected cabin." :data-seats="json_encode($seatMap)" />
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
            const offered = seats.filter((seat) => seat.cabin === cabin);
            const map = document.getElementById('seat-map');
            const hint = document.getElementById('seat-map-hint');
            hint.textContent = `Free ${cabin} seats. Taken seats blocked.`;

            window.ArcsSeatMap.draw(map, offered, (seat) => {
                const mine = seatInput.value === String(seat.id);
                let style = window.ArcsSeatMap.cabinStyle({ ...seat, taken: false });
                let disabled = seat.taken;
                if (seat.taken) style = 'taken';
                if (mine) style = 'mine';
                return { style, disabled, onPick: (s) => {
                    if (s.taken || s.cabin !== cabin) return;
                    seatInput.value = seatInput.value === String(s.id) ? '' : String(s.id);
                    paint();
                }};
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
