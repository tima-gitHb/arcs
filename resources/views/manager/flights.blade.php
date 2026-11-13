<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Flights and aircraft</h1>
    </x-slot>

    <div class="space-y-6">
        {{-- A refused status move (StatusService said no) comes back under the 'status' key. --}}
        @if ($errors->has('status'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                {{ $errors->first('status') }}
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- New aircraft: saving it also generates its seats (SR-14). --}}
            <div class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
                <h2 class="text-lg font-semibold text-navy">New aircraft</h2>
                <p class="mt-1 text-sm text-gray-600">Seats are generated from the layout: the first rows are Business, the next rows are Premium, the rest Economy.</p>

                <form method="POST" action="{{ route('manager.aircraft.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                    @csrf

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
                        <x-text-input id="rows" name="rows" type="number" min="1" max="99" class="mt-1 block w-full" :value="old('rows')" required />
                        <x-input-error :messages="$errors->get('rows')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="seats_per_row" value="Seats per row (1–10)" />
                        <x-text-input id="seats_per_row" name="seats_per_row" type="number" min="1" max="10" class="mt-1 block w-full" :value="old('seats_per_row')" required />
                        <x-input-error :messages="$errors->get('seats_per_row')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="business_rows" value="Business rows (from row 1)" />
                        <x-text-input id="business_rows" name="business_rows" type="number" min="0" max="99" class="mt-1 block w-full" :value="old('business_rows', 0)" required />
                        <x-input-error :messages="$errors->get('business_rows')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="premium_rows" value="Premium rows (after Business)" />
                        <x-text-input id="premium_rows" name="premium_rows" type="number" min="0" max="99" class="mt-1 block w-full" :value="old('premium_rows', 0)" required />
                        <x-input-error :messages="$errors->get('premium_rows')" class="mt-2" />
                    </div>

                    <div class="sm:pt-6">
                        <x-primary-button>Create aircraft</x-primary-button>
                    </div>
                </form>
            </div>

            {{-- New flight --}}
            <div class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
                <h2 class="text-lg font-semibold text-navy">New flight</h2>
                <p class="mt-1 text-sm text-gray-600">A new flight starts as Scheduled. Times are Beirut time.</p>

                <form method="POST" action="{{ route('manager.flights.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                    @csrf

                    <div>
                        <x-input-label for="flight_no" value="Flight number" />
                        <x-text-input id="flight_no" name="flight_no" type="text" class="mt-1 block w-full uppercase" :value="old('flight_no')" placeholder="ME205" required />
                        <x-input-error :messages="$errors->get('flight_no')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="aircraft_id" value="Aircraft" />
                        <select id="aircraft_id" name="aircraft_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                            <option value="" disabled @selected(! old('aircraft_id'))>Choose an aircraft</option>
                            @foreach ($aircraft as $plane)
                                <option value="{{ $plane->id }}" @selected((string) old('aircraft_id') === (string) $plane->id)>
                                    {{ $plane->registration }} — {{ $plane->model }} ({{ $plane->seats_count }} seats)
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('aircraft_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="origin" value="From (airport code)" />
                        <x-text-input id="origin" name="origin" type="text" maxlength="3" list="airport-codes" class="mt-1 block w-full uppercase" :value="old('origin')" placeholder="BEY" required />
                        <x-input-error :messages="$errors->get('origin')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="destination" value="To (airport code)" />
                        <x-text-input id="destination" name="destination" type="text" maxlength="3" list="airport-codes" class="mt-1 block w-full uppercase" :value="old('destination')" placeholder="CDG" required />
                        <x-input-error :messages="$errors->get('destination')" class="mt-2" />
                        {{-- Only airports of the network are accepted (B13a). --}}
                        <datalist id="airport-codes">
                            @foreach ($airports as $airport)
                                <option value="{{ $airport->code }}">{{ $airport->city }} — {{ $airport->name }}</option>
                            @endforeach
                        </datalist>
                    </div>

                    <div>
                        <x-input-label for="departure_at" value="Departure (local time at origin)" />
                        <x-text-input id="departure_at" name="departure_at" type="datetime-local" class="mt-1 block w-full" :value="old('departure_at')" required />
                        <x-input-error :messages="$errors->get('departure_at')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="arrives_at" value="Arrival (local time at destination, optional)" />
                        <x-text-input id="arrives_at" name="arrives_at" type="datetime-local" class="mt-1 block w-full" :value="old('arrives_at')" />
                        <x-input-error :messages="$errors->get('arrives_at')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="gate" value="Gate (optional)" />
                        <x-text-input id="gate" name="gate" type="text" maxlength="3" class="mt-1 block w-full uppercase" :value="old('gate')" placeholder="B4" oninput="this.value = this.value.toUpperCase()" />
                        <x-input-error :messages="$errors->get('gate')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="checkin_opens_at" value="Check-in opens (local time at origin)" />
                        <x-text-input id="checkin_opens_at" name="checkin_opens_at" type="datetime-local" class="mt-1 block w-full" :value="old('checkin_opens_at')" required />
                        <x-input-error :messages="$errors->get('checkin_opens_at')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="checkin_closes_at" value="Check-in closes (local time at origin)" />
                        <x-text-input id="checkin_closes_at" name="checkin_closes_at" type="datetime-local" class="mt-1 block w-full" :value="old('checkin_closes_at')" required />
                        <x-input-error :messages="$errors->get('checkin_closes_at')" class="mt-2" />
                    </div>

                    <div class="sm:col-span-2">
                        <x-primary-button>Create flight</x-primary-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Flight list: an editable gate and one button per move StatusService allows (SR-25, SR-42). --}}
        <div class="bg-white rounded-lg shadow-sm overflow-x-auto">
            @if ($flights->isEmpty())
                <p class="px-4 py-4 text-sm text-gray-600">No flights yet.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-2 font-medium">Flight</th>
                            <th class="px-4 py-2 font-medium">Route</th>
                            <th class="px-4 py-2 font-medium">Departure</th>
                            <th class="px-4 py-2 font-medium">Check-in window</th>
                            <th class="px-4 py-2 font-medium">Aircraft</th>
                            <th class="px-4 py-2 font-medium">Gate</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 font-medium">Change status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($flights as $flight)
                            <tr class="border-b border-gray-100 last:border-0 align-top">
                                <td class="px-4 py-2 font-medium">{{ $flight->flight_no }}</td>
                                <td class="px-4 py-2">{{ $flight->origin }} → {{ $flight->destination }}</td>
                                <td class="px-4 py-2 whitespace-nowrap"><x-local-time :at="$flight->departure_at" :airport="$flight->origin" /></td>
                                <td class="px-4 py-2 whitespace-nowrap"><x-local-time :at="$flight->checkin_opens_at" :airport="$flight->origin" format="H:i" />–<x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="H:i" /></td>
                                <td class="px-4 py-2">{{ $flight->aircraft->registration }}</td>
                                <td class="px-4 py-2">
                                    <form method="POST" action="{{ route('manager.flights.gate', $flight) }}" class="flex items-center gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <input type="text" name="gate" value="{{ $flight->gate }}" maxlength="3" pattern="[A-Z][0-9]{1,2}"
                                               aria-label="Gate for {{ $flight->flight_no }}" placeholder="—"
                                               oninput="this.value = this.value.toUpperCase()"
                                               class="w-16 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                        <button type="submit" class="text-indigo-600 hover:underline">Save</button>
                                    </form>
                                </td>
                                <td class="px-4 py-2">{{ $flight->status->label() }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex flex-wrap gap-2">
                                        @forelse ($moves[$flight->id] as $move)
                                            <form method="POST" action="{{ route('manager.flights.status', $flight) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" name="status" value="{{ $move->value }}"
                                                        class="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50">
                                                    {{ $move->label() }}
                                                </button>
                                            </form>
                                        @empty
                                            <span class="text-gray-500">—</span>
                                        @endforelse
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-app-layout>
