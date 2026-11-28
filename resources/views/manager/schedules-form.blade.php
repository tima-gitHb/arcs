@php
    $days = old('days_of_week', $schedule->days_of_week ?? []);
    $days = is_array($days) ? array_map('intval', $days) : [];
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $active = old('active', $schedule->active ?? true);
@endphp
<x-app-layout>
    <x-page-header
        :title="$editing ? 'Edit schedule' : 'New schedule'"
        :subtitle="$editing ? 'Changes apply to future unbooked generated flights.' : 'A weekly pattern that can generate dated flights.'"
    >
        <x-slot:breadcrumb>
            <span class="text-gray-400">Flights and aircraft</span>
            <span class="text-gray-300">›</span>
            <a href="{{ route('manager.schedules.index') }}" class="hover:underline">Schedules</a>
            <span class="text-gray-300">›</span>
            <span>{{ $editing ? 'Edit' : 'New schedule' }}</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <form
        method="POST"
        action="{{ $editing ? route('manager.schedules.update', $schedule) : route('manager.schedules.store') }}"
        class="space-y-6"
    >
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <x-panel title="Route and aircraft">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="flight_no" value="Flight number" />
                    <x-text-input id="flight_no" name="flight_no" type="text" class="mt-1 block w-full uppercase" :value="old('flight_no', $schedule->flight_no)" placeholder="{{ config('arcs.airline.code') }}211" required />
                    <x-input-error :messages="$errors->get('flight_no')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="aircraft_id" value="Aircraft" />
                    <select id="aircraft_id" name="aircraft_id" class="arcs-input mt-1 block w-full" required>
                        <option value="" disabled @selected(! old('aircraft_id', $schedule->aircraft_id))>Choose an aircraft</option>
                        @foreach ($aircraft as $plane)
                            <option value="{{ $plane->id }}" @selected((string) old('aircraft_id', $schedule->aircraft_id) === (string) $plane->id)>
                                {{ $plane->registration }} — {{ $plane->model }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('aircraft_id')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="origin" value="From" />
                    <x-text-input id="origin" name="origin" type="text" maxlength="3" list="airport-codes" class="mt-1 block w-full uppercase" :value="old('origin', $schedule->origin)" placeholder="BEY" required />
                    <x-input-error :messages="$errors->get('origin')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="destination" value="To" />
                    <x-text-input id="destination" name="destination" type="text" maxlength="3" list="airport-codes" class="mt-1 block w-full uppercase" :value="old('destination', $schedule->destination)" placeholder="CDG" required />
                    <x-input-error :messages="$errors->get('destination')" class="mt-2" />
                    <datalist id="airport-codes">
                        @foreach ($airports as $airport)
                            <option value="{{ $airport->code }}">{{ $airport->city }} — {{ $airport->name }}</option>
                        @endforeach
                    </datalist>
                </div>
                <div>
                    <x-input-label for="gate" value="Default gate (optional)" />
                    <x-text-input id="gate" name="gate" type="text" maxlength="3" class="mt-1 block w-full uppercase" :value="old('gate', $schedule->gate)" placeholder="B4" oninput="this.value = this.value.toUpperCase()" />
                    <x-input-error :messages="$errors->get('gate')" class="mt-2" />
                </div>
            </div>
        </x-panel>

        <x-panel title="Weekly pattern">
            <p class="mb-3 text-sm text-gray-600">Operating days</p>
            <div class="flex flex-wrap gap-2" x-data="{ days: @js(array_values($days)) }">
                @foreach ($dayNames as $value => $label)
                    <label class="cursor-pointer">
                        <input type="checkbox" name="days_of_week[]" value="{{ $value }}" class="sr-only" x-model.number="days">
                        <span
                            class="inline-flex min-w-[3rem] items-center justify-center rounded-full px-3 py-1.5 text-sm"
                            :class="days.includes({{ $value }}) ? 'font-semibold text-white' : 'text-gray-600'"
                            :style="days.includes({{ $value }}) ? 'background: var(--arcs-bar)' : 'background: #EEF0F4'"
                        >{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('days_of_week')" class="mt-2" />

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="departure_local_time" value="Departure (local at origin)" />
                    <x-text-input id="departure_local_time" name="departure_local_time" type="time" class="mt-1 block w-full" :value="old('departure_local_time', $schedule->exists ? $schedule->departureHm() : '09:40')" required />
                    <p class="mt-1 text-xs text-gray-500">Kept as a local clock across daylight-saving changes</p>
                    <x-input-error :messages="$errors->get('departure_local_time')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="block_minutes" value="Block time (minutes)" />
                    <x-text-input id="block_minutes" name="block_minutes" type="number" min="30" max="1080" class="mt-1 block w-full" :value="old('block_minutes', $schedule->block_minutes ?? 240)" required />
                    <x-input-error :messages="$errors->get('block_minutes')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="checkin_opens_minutes_before" value="Check-in opens (minutes before)" />
                    <x-text-input id="checkin_opens_minutes_before" name="checkin_opens_minutes_before" type="number" min="30" max="720" class="mt-1 block w-full" :value="old('checkin_opens_minutes_before', $schedule->checkin_opens_minutes_before ?? 180)" />
                    <x-input-error :messages="$errors->get('checkin_opens_minutes_before')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="checkin_closes_minutes_before" value="Check-in closes (minutes before)" />
                    <x-text-input id="checkin_closes_minutes_before" name="checkin_closes_minutes_before" type="number" min="15" max="360" class="mt-1 block w-full" :value="old('checkin_closes_minutes_before', $schedule->checkin_closes_minutes_before ?? 45)" />
                    <x-input-error :messages="$errors->get('checkin_closes_minutes_before')" class="mt-2" />
                </div>
            </div>
        </x-panel>

        <x-panel title="Validity">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="valid_from" value="Valid from" />
                    <x-text-input id="valid_from" name="valid_from" type="date" class="mt-1 block w-full" :value="old('valid_from', optional($schedule->valid_from)->toDateString())" required />
                    <x-input-error :messages="$errors->get('valid_from')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="valid_to" value="Valid to" />
                    <x-text-input id="valid_to" name="valid_to" type="date" class="mt-1 block w-full" :value="old('valid_to', optional($schedule->valid_to)->toDateString())" required />
                    <p class="mt-1 text-xs text-gray-500">At most 1 year from valid from</p>
                    <x-input-error :messages="$errors->get('valid_to')" class="mt-2" />
                </div>
                <div class="sm:col-span-2">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="active" value="1" class="rounded border-gray-300" @checked((bool) $active)>
                        Active (generate new flights from this schedule)
                    </label>
                </div>
            </div>
        </x-panel>

        <div class="flex flex-wrap gap-3">
            <a href="{{ $editing ? route('manager.schedules.show', $schedule) : route('manager.schedules.index') }}" class="arcs-btn-secondary">Cancel</a>
            <x-primary-button>{{ $editing ? 'Save schedule' : 'Create schedule' }}</x-primary-button>
        </div>
    </form>
</x-app-layout>
