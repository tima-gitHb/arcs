<x-app-layout>
    <x-page-header title="New aircraft" subtitle="Seats are generated from the layout when you create the aircraft.">
        <x-slot:breadcrumb>
            <span class="text-gray-400">Flights and aircraft</span>
            <span class="text-gray-300">›</span>
            <a href="{{ route('manager.aircraft.index') }}" class="hover:underline">Aircraft fleet</a>
            <span class="text-gray-300">›</span>
            <span>New aircraft</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <form method="POST" action="{{ route('manager.aircraft.store') }}" class="grid gap-6 xl:grid-cols-[1fr_24rem]"
        x-data="aircraftPreview({{ (int) $maxSeats }})">
        @csrf

        <x-panel title="Layout">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="registration" value="Registration" />
                    <x-text-input id="registration" name="registration" type="text" class="mt-1 block w-full" :value="old('registration')" placeholder="OD-MRA" required />
                    <x-input-error :messages="$errors->get('registration')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="model" value="Model" />
                    <x-text-input id="model" name="model" type="text" class="mt-1 block w-full" :value="old('model')" placeholder="A320" required />
                    <x-input-error :messages="$errors->get('model')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="rows" value="Rows (1–99)" />
                    <x-text-input id="rows" name="rows" type="number" min="1" max="99" class="mt-1 block w-full" :value="old('rows')" required x-model.number="rows" />
                    <x-input-error :messages="$errors->get('rows')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="seats_per_row" value="Seats per row (1–10)" />
                    <x-text-input id="seats_per_row" name="seats_per_row" type="number" min="1" max="10" class="mt-1 block w-full" :value="old('seats_per_row')" required x-model.number="seatsPerRow" />
                    <x-input-error :messages="$errors->get('seats_per_row')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="business_rows" value="Business rows (from row 1)" />
                    <x-text-input id="business_rows" name="business_rows" type="number" min="0" max="99" class="mt-1 block w-full" :value="old('business_rows', 0)" required x-model.number="businessRows" />
                    <x-input-error :messages="$errors->get('business_rows')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="premium_rows" value="Premium rows (after Business)" />
                    <x-text-input id="premium_rows" name="premium_rows" type="number" min="0" max="99" class="mt-1 block w-full" :value="old('premium_rows', 0)" required x-model.number="premiumRows" />
                    <x-input-error :messages="$errors->get('premium_rows')" class="mt-2" />
                </div>
            </div>

            <p class="mt-4 text-sm text-gray-700">
                <span x-text="businessSeats"></span> Business ·
                <span x-text="premiumSeats"></span> Premium ·
                <span x-text="economySeats"></span> Economy ·
                <span class="font-semibold" x-text="totalSeats"></span> total seats
            </p>
            <p class="mt-2 text-sm" style="color: var(--arcs-error);" x-show="totalSeats > maxSeats" x-cloak>
                Total seats cannot exceed {{ $maxSeats }}.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('manager.aircraft.index') }}" class="arcs-btn-secondary">Cancel</a>
                <x-primary-button x-bind:disabled="totalSeats > maxSeats">Create aircraft</x-primary-button>
            </div>
        </x-panel>

        <x-seat-map hint="Live preview of the layout" mode="booking" />
    </form>

    <script>
        function aircraftPreview(maxSeats) {
            return {
                rows: {{ (int) old('rows', 30) }},
                seatsPerRow: {{ (int) old('seats_per_row', 6) }},
                businessRows: {{ (int) old('business_rows', 0) }},
                premiumRows: {{ (int) old('premium_rows', 0) }},
                maxSeats,
                get businessSeats() { return Math.max(0, this.businessRows) * Math.max(0, this.seatsPerRow); },
                get premiumSeats() { return Math.max(0, this.premiumRows) * Math.max(0, this.seatsPerRow); },
                get economySeats() {
                    const ecoRows = Math.max(0, this.rows - this.businessRows - this.premiumRows);
                    return ecoRows * Math.max(0, this.seatsPerRow);
                },
                get totalSeats() { return this.businessSeats + this.premiumSeats + this.economySeats; },
                init() {
                    this.$watch('rows', () => this.draw());
                    this.$watch('seatsPerRow', () => this.draw());
                    this.$watch('businessRows', () => this.draw());
                    this.$watch('premiumRows', () => this.draw());
                    this.draw();
                },
                draw() {
                    const map = document.getElementById('seat-map');
                    if (! map || ! window.ArcsSeatMap) return;
                    const letters = 'ABCDEFGHIJ'.slice(0, Math.min(10, Math.max(1, this.seatsPerRow || 1))).split('');
                    const seats = [];
                    let id = 1;
                    for (let row = 1; row <= Math.max(0, this.rows || 0); row++) {
                        const cabin = row <= this.businessRows ? 'Business' : (row <= this.businessRows + this.premiumRows ? 'Premium' : 'Economy');
                        letters.forEach((letter) => {
                            seats.push({ id: id++, seat_no: row + letter, row, letter, cabin, taken: false });
                        });
                    }
                    window.ArcsSeatMap.draw(map, seats, (seat) => ({
                        style: window.ArcsSeatMap.cabinStyle(seat),
                        disabled: true,
                    }));
                },
            };
        }
    </script>
</x-app-layout>
