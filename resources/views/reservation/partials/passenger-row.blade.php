@php
    $value = $passenger['accompanying_adult'] ?? '';
    $type = $passenger['type'] ?? 'Adult';
@endphp

<div class="passenger-row rounded-lg border border-gray-200 p-4" data-index="{{ $index }}">
    <div class="mb-3 flex items-center justify-between gap-3">
        <h3 class="font-medium text-navy">
            Passenger <span class="passenger-number">{{ is_numeric($index) ? ((int) $index + 1) : '' }}</span>
        </h3>
        <button type="button" class="remove-passenger text-sm text-red-700 hover:underline {{ $canRemove ? '' : 'hidden' }}">
            Remove
        </button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label :for="'passenger-'.$index.'-first-name'" value="First name" />
            <x-text-input
                :id="'passenger-'.$index.'-first-name'"
                class="passenger-first mt-1 block w-full"
                type="text"
                :name="'passengers['.$index.'][first_name]'"
                :value="$passenger['first_name'] ?? ''"
                required
                maxlength="255"
                autocomplete="off"
            />
            <x-input-error :messages="$errors->get('passengers.'.$index.'.first_name')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'passenger-'.$index.'-last-name'" value="Last name" />
            <x-text-input
                :id="'passenger-'.$index.'-last-name'"
                class="passenger-last mt-1 block w-full"
                type="text"
                :name="'passengers['.$index.'][last_name]'"
                :value="$passenger['last_name'] ?? ''"
                required
                maxlength="255"
                autocomplete="off"
            />
            <x-input-error :messages="$errors->get('passengers.'.$index.'.last_name')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'passenger-'.$index.'-type'" value="Type" />
            <select
                id="passenger-{{ $index }}-type"
                name="passengers[{{ $index }}][type]"
                class="passenger-type mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                required
            >
                @foreach (App\Enums\PassengerType::cases() as $passengerType)
                    <option value="{{ $passengerType->value }}" @selected($type === $passengerType->value)>
                        {{ $passengerType->label() }}
                    </option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('passengers.'.$index.'.type')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'passenger-'.$index.'-date-of-birth'" value="Date of birth" />
            <x-text-input
                :id="'passenger-'.$index.'-date-of-birth'"
                class="mt-1 block w-full"
                type="date"
                :name="'passengers['.$index.'][date_of_birth]'"
                :value="$passenger['date_of_birth'] ?? ''"
                :max="now()->toDateString()"
                required
            />
            <x-input-error :messages="$errors->get('passengers.'.$index.'.date_of_birth')" class="mt-2" />
        </div>
    </div>

    <div class="accompanying-field mt-4 {{ $type === 'Infant' ? '' : 'hidden' }}">
        <x-input-label :for="'passenger-'.$index.'-accompanying-adult'" value="Travelling with" />
        <select
            id="passenger-{{ $index }}-accompanying-adult"
            name="passengers[{{ $index }}][accompanying_adult]"
            class="accompanying-adult mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        >
            <option value="">Select an adult</option>
            @foreach ($adultOptions as $adultIndex => $adultLabel)
                <option value="{{ $adultIndex }}" @selected((string) $value === (string) $adultIndex)>
                    {{ $adultLabel }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-sm text-gray-500">An infant travels with an adult on this reservation and does not get a seat.</p>
        <x-input-error :messages="$errors->get('passengers.'.$index.'.accompanying_adult')" class="mt-2" />
    </div>

    <div class="seat-field mt-4 {{ $type === 'Infant' ? 'hidden' : '' }}">
        <p class="text-sm font-medium text-gray-700">Seat</p>
        <p class="seat-label mt-1 text-sm text-navy">Not chosen</p>
        <input type="hidden" class="passenger-seat" name="passengers[{{ $index }}][seat_id]" value="{{ $passenger['seat_id'] ?? '' }}">
        <button type="button" class="pick-seat mt-1 text-sm font-medium text-navy hover:underline">Choose on the map</button>
        <x-input-error :messages="$errors->get('passengers.'.$index.'.seat_id')" class="mt-2" />
    </div>
</div>
