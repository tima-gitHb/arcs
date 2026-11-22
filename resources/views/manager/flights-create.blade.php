@php
    $selectedAircraft = $aircraft->firstWhere('id', (int) old('aircraft_id'));
@endphp
<x-app-layout>
    <x-page-header title="New flight" subtitle="A new flight starts as Scheduled. Times are local at each airport.">
        <x-slot:breadcrumb>
            <span class="text-gray-400">Flights and aircraft</span>
            <span class="text-gray-300">›</span>
            <a href="{{ route('manager.flights') }}" class="hover:underline">Flights</a>
            <span class="text-gray-300">›</span>
            <span>New flight</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <form method="POST" action="{{ route('manager.flights.store') }}" class="grid gap-6 xl:grid-cols-[1fr_22rem]" x-data="flightPreview()">
        @csrf

        <div class="space-y-6">
            <x-panel title="Route and aircraft">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="flight_no" value="Flight number" />
                        <x-text-input id="flight_no" name="flight_no" type="text" class="mt-1 block w-full uppercase" :value="old('flight_no')" placeholder="{{ config('arcs.airline.code') }}205" required x-model="flightNo" />
                        <x-input-error :messages="$errors->get('flight_no')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="aircraft_id" value="Aircraft" />
                        <select id="aircraft_id" name="aircraft_id" class="arcs-input mt-1 block w-full" required x-model="aircraftId" @change="onAircraft()">
                            <option value="" disabled @selected(! old('aircraft_id'))>Choose an aircraft</option>
                            @foreach ($aircraft as $plane)
                                <option value="{{ $plane->id }}" data-reg="{{ $plane->registration }}" data-seats="{{ $plane->seats_count }}" @selected((string) old('aircraft_id') === (string) $plane->id)>
                                    {{ $plane->registration }} — {{ $plane->model }} ({{ $plane->seats_count }} seats)
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('aircraft_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="origin" value="From" />
                        <x-text-input id="origin" name="origin" type="text" maxlength="3" list="airport-codes" class="mt-1 block w-full uppercase" :value="old('origin')" placeholder="BEY" required x-model="origin" />
                        <x-input-error :messages="$errors->get('origin')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="destination" value="To" />
                        <x-text-input id="destination" name="destination" type="text" maxlength="3" list="airport-codes" class="mt-1 block w-full uppercase" :value="old('destination')" placeholder="CDG" required x-model="destination" />
                        <x-input-error :messages="$errors->get('destination')" class="mt-2" />
                        <datalist id="airport-codes">
                            @foreach ($airports as $airport)
                                <option value="{{ $airport->code }}">{{ $airport->city }} — {{ $airport->name }}</option>
                            @endforeach
                        </datalist>
                    </div>
                    <div>
                        <x-input-label for="gate" value="Gate (optional)" />
                        <x-text-input id="gate" name="gate" type="text" maxlength="3" class="mt-1 block w-full uppercase" :value="old('gate')" placeholder="B4" oninput="this.value = this.value.toUpperCase()" />
                        <p class="mt-1 text-xs text-gray-500">Letter + 1–2 digits</p>
                        <x-input-error :messages="$errors->get('gate')" class="mt-2" />
                    </div>
                </div>
            </x-panel>

            <x-panel title="Times">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="departure_at" value="Departure (local at origin)" />
                        <x-text-input id="departure_at" name="departure_at" type="datetime-local" class="mt-1 block w-full" :value="old('departure_at')" required />
                        <p class="mt-1 text-xs text-gray-500">Hint: afternoon departures are common for long-haul</p>
                        <x-input-error :messages="$errors->get('departure_at')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="arrives_at" value="Arrival (local at destination, optional)" />
                        <x-text-input id="arrives_at" name="arrives_at" type="datetime-local" class="mt-1 block w-full" :value="old('arrives_at')" />
                        <x-input-error :messages="$errors->get('arrives_at')" class="mt-2" />
                    </div>
                </div>
            </x-panel>

            <x-panel title="Check-in window">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="checkin_opens_at" value="Opens (local at origin)" />
                        <x-text-input id="checkin_opens_at" name="checkin_opens_at" type="datetime-local" class="mt-1 block w-full" :value="old('checkin_opens_at')" required />
                        <p class="mt-1 text-xs text-gray-500">Hint: typically 3 hours before departure</p>
                        <x-input-error :messages="$errors->get('checkin_opens_at')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="checkin_closes_at" value="Closes (local at origin)" />
                        <x-text-input id="checkin_closes_at" name="checkin_closes_at" type="datetime-local" class="mt-1 block w-full" :value="old('checkin_closes_at')" required />
                        <p class="mt-1 text-xs text-gray-500">Hint: typically 45 minutes before departure</p>
                        <x-input-error :messages="$errors->get('checkin_closes_at')" class="mt-2" />
                    </div>
                </div>
            </x-panel>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('manager.flights') }}" class="arcs-btn-secondary">Cancel</a>
                <x-primary-button>Create flight</x-primary-button>
            </div>
        </div>

        <aside class="rounded-[22px] border bg-white p-5 shadow-sm xl:sticky xl:top-6" style="border-color: var(--arcs-border);">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Preview</p>
            <p class="mt-2 text-2xl font-bold arcs-mono" x-text="flightNo || '{{ config('arcs.airline.code') }}…'"></p>
            <p class="mt-1 text-sm text-gray-600">
                <span x-text="(origin || '—').toUpperCase()"></span> → <span x-text="(destination || '—').toUpperCase()"></span>
            </p>
            <p class="mt-4 text-sm text-gray-600">Aircraft <span class="font-medium text-gray-900" x-text="aircraftLabel || '—'"></span></p>
            <p class="mt-1 text-sm text-gray-600">Seats <span class="font-medium text-gray-900" x-text="seats || '—'"></span></p>
            <div class="mt-4">
                <x-status-badge :status="\App\Enums\FlightStatus::Scheduled" />
            </div>
        </aside>
    </form>

    <script>
        function flightPreview() {
            return {
                flightNo: @json(old('flight_no', '')),
                origin: @json(old('origin', '')),
                destination: @json(old('destination', '')),
                aircraftId: @json(old('aircraft_id', '')),
                aircraftLabel: '',
                seats: '',
                init() { this.onAircraft(); },
                onAircraft() {
                    const opt = document.querySelector('#aircraft_id option:checked');
                    this.aircraftLabel = opt?.dataset?.reg || '';
                    this.seats = opt?.dataset?.seats || '';
                },
            };
        }
    </script>
</x-app-layout>
