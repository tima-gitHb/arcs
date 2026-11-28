@php
    $value = $passenger['accompanying_adult'] ?? '';
    $type = $passenger['type'] ?? 'Adult';
    $cabin = $passenger['cabin_class'] ?? App\Enums\CabinClass::Economy->value;
    $cabinOptions = $cabinOptions ?? collect();
@endphp

<div class="passenger-row rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-index="{{ $index }}">
    <div class="passenger-head mb-3 flex items-center justify-between gap-3">
        <h3 class="font-medium text-navy">
            <span class="passenger-title-full">Passenger </span><span class="passenger-number">{{ is_numeric($index) ? ((int) $index + 1) : '' }}</span>
            <span class="passenger-name-short ml-1 text-sm font-semibold text-gray-800"></span>
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
            <x-input-label :for="'passenger-'.$index.'-type'" value="Passenger type" />
            <select
                id="passenger-{{ $index }}-type"
                name="passengers[{{ $index }}][type]"
                class="passenger-type mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-navy focus:ring-navy"
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

        <div class="email-field sm:col-span-2 {{ $type === 'Infant' ? 'hidden' : '' }}">
            <x-input-label :for="'passenger-'.$index.'-email'" value="Email (optional)" />
            <x-text-input
                :id="'passenger-'.$index.'-email'"
                class="passenger-email mt-1 block w-full"
                type="email"
                :name="'passengers['.$index.'][email]'"
                :value="$passenger['email'] ?? ''"
                maxlength="255"
                autocomplete="off"
                placeholder="boarding passes…"
            />
            <p class="mt-1 text-xs text-gray-500">Used for boarding-pass email at check-in. Otherwise the booking contact is used.</p>
            <x-input-error :messages="$errors->get('passengers.'.$index.'.email')" class="mt-2" />
        </div>

        <div class="cabin-field sm:col-span-2 {{ $type === 'Infant' ? 'hidden' : '' }}">
            <x-input-label :for="'passenger-'.$index.'-cabin-class'" value="Cabin class" />
            <select
                id="passenger-{{ $index }}-cabin-class"
                name="passengers[{{ $index }}][cabin_class]"
                class="passenger-cabin mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-navy focus:ring-navy"
                @required($type !== 'Infant')
            >
                @foreach ($cabinOptions as $option)
                    <option value="{{ $option['value'] }}" @selected($cabin === $option['value'])>
                        {{ $option['label'] }}
                        @if ($option['fare_label'])
                            — {{ $option['fare_label'] }}
                        @endif
                        @if ($option['allowance'])
                            · {{ $option['allowance']['pieces'] }}×{{ rtrim(rtrim(number_format($option['allowance']['kg'], 1, '.', ''), '0'), '.') }} kg
                        @endif
                    </option>
                @endforeach
            </select>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="passenger-fare-label font-medium text-navy"></span>
                <span class="allowance-label rounded-full bg-[#E8F0FA] px-2.5 py-0.5 text-xs font-medium text-navy"></span>
            </div>
            <p class="mt-1 text-xs text-gray-500">Map filters to this cabin. Quote updates on the right.</p>
            <x-input-error :messages="$errors->get('passengers.'.$index.'.cabin_class')" class="mt-2" />
        </div>
    </div>

    <div class="extras-field mt-4 rounded-md border border-dashed border-navy/20 bg-[#F7FAFD] p-4 {{ $type === 'Infant' ? 'hidden' : '' }}">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h4 class="text-sm font-medium text-navy">Prepaid baggage extras</h4>
            <p class="text-xs text-gray-500">Charged with the fare · visible at check-in</p>
        </div>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label :for="'passenger-'.$index.'-extra-bags'" value="Extra bags" />
                <div class="mt-1 flex items-center gap-2">
                    <x-text-input
                        :id="'passenger-'.$index.'-extra-bags'"
                        class="passenger-extra-bags block w-24"
                        type="number"
                        min="0"
                        max="10"
                        step="1"
                        :name="'passengers['.$index.'][extra_bags]'"
                        :value="$passenger['extra_bags'] ?? 0"
                    />
                    <span class="extra-bag-price text-sm text-gray-600"></span>
                </div>
                <x-input-error :messages="$errors->get('passengers.'.$index.'.extra_bags')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="'passenger-'.$index.'-extra-kg'" value="Extra kg" />
                <div class="mt-1 flex items-center gap-2">
                    <x-text-input
                        :id="'passenger-'.$index.'-extra-kg'"
                        class="passenger-extra-kg block w-24"
                        type="number"
                        min="0"
                        max="100"
                        step="1"
                        :name="'passengers['.$index.'][extra_kg]'"
                        :value="$passenger['extra_kg'] ?? 0"
                    />
                    <span class="extra-kg-price text-sm text-gray-600"></span>
                </div>
                <x-input-error :messages="$errors->get('passengers.'.$index.'.extra_kg')" class="mt-2" />
            </div>
        </div>
    </div>

    <div class="accompanying-field mt-4 {{ $type === 'Infant' ? '' : 'hidden' }}">
        <x-input-label :for="'passenger-'.$index.'-accompanying-adult'" value="Travelling with" />
        <select
            id="passenger-{{ $index }}-accompanying-adult"
            name="passengers[{{ $index }}][accompanying_adult]"
            class="accompanying-adult mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-navy focus:ring-navy"
        >
            <option value="">Select an adult</option>
            @foreach ($adultOptions as $adultIndex => $adultLabel)
                <option value="{{ $adultIndex }}" @selected((string) $value === (string) $adultIndex)>
                    {{ $adultLabel }}
                </option>
            @endforeach
        </select>
        <p class="mt-1 text-sm text-gray-500">Infant must be linked to an adult on this PNR. No seat.</p>
        <x-input-error :messages="$errors->get('passengers.'.$index.'.accompanying_adult')" class="mt-2" />
    </div>

    <div class="passport-field mt-4 rounded-md border border-gray-100 bg-gray-50 p-4">
        <h4 class="text-sm font-medium text-navy">Passport</h4>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label :for="'passenger-'.$index.'-passport-number'" value="Number" />
                <x-text-input
                    :id="'passenger-'.$index.'-passport-number'"
                    class="mt-1 block w-full font-mono uppercase"
                    type="text"
                    :name="'passengers['.$index.'][passport][number]'"
                    :value="$passenger['passport']['number'] ?? ''"
                    required
                    maxlength="30"
                    autocomplete="off"
                />
                <x-input-error :messages="$errors->get('passengers.'.$index.'.passport.number')" class="mt-2" />
            </div>

            <div>
                <x-input-label :for="'passenger-'.$index.'-passport-expiry'" value="Expiry date" />
                <x-text-input
                    :id="'passenger-'.$index.'-passport-expiry'"
                    class="mt-1 block w-full"
                    type="date"
                    :name="'passengers['.$index.'][passport][expiry_date]'"
                    :value="$passenger['passport']['expiry_date'] ?? ''"
                    required
                />
                <x-input-error :messages="$errors->get('passengers.'.$index.'.passport.expiry_date')" class="mt-2" />
            </div>

            <div class="sm:col-span-2">
                <x-input-label :for="'passenger-'.$index.'-passport-country'" value="Issuing country" />
                <select
                    id="passenger-{{ $index }}-passport-country"
                    name="passengers[{{ $index }}][passport][issuing_country]"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-navy focus:ring-navy"
                    required
                >
                    <option value="">Choose a country…</option>
                    @foreach (app(\App\Services\CountryList::class)->all() as $code => $country)
                        <option value="{{ $code }}" @selected(($passenger['passport']['issuing_country'] ?? null) === $code)>
                            {{ $country }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('passengers.'.$index.'.passport.issuing_country')" class="mt-2" />
            </div>
        </div>
    </div>

    <div class="seat-field mt-4 {{ $type === 'Infant' ? 'hidden' : '' }}">
        <p class="text-sm font-medium text-gray-700">Seats</p>
        {{-- Summary only on the passengers step; plane lives on the Seats step. --}}
        <div class="seat-assignments mt-2 space-y-2">
            @php
                $seatMap = is_array($passenger['seats'] ?? null) ? $passenger['seats'] : [];
                if (($passenger['seat_id'] ?? null) && $seatMap === []) {
                    $seatMap['legacy'] = $passenger['seat_id'];
                }
            @endphp
            @forelse ($seatMap as $flightId => $seatId)
                @if ($flightId === 'legacy')
                    <div class="seat-leg flex flex-wrap items-center gap-2" data-flight-id="">
                        <span class="seat-leg-label text-sm text-gray-600">Seat</span>
                        <span class="seat-label text-sm font-semibold text-navy">Chosen</span>
                        <input type="hidden" class="passenger-seat" name="passengers[{{ $index }}][seat_id]" value="{{ $seatId }}">
                        <button type="button" class="pick-seat text-sm font-medium text-navy hover:underline">Assign on map</button>
                    </div>
                @else
                    <div class="seat-leg flex flex-wrap items-center gap-2" data-flight-id="{{ $flightId }}">
                        <span class="seat-leg-label text-sm text-gray-600">Flight {{ $flightId }}</span>
                        <span class="seat-label text-sm font-semibold text-navy">Chosen</span>
                        <input type="hidden" class="passenger-seat" name="passengers[{{ $index }}][seats][{{ $flightId }}]" value="{{ $seatId }}">
                        <button type="button" class="pick-seat text-sm font-medium text-navy hover:underline">Assign on map</button>
                        <x-input-error :messages="$errors->get('passengers.'.$index.'.seats.'.$flightId)" class="w-full" />
                    </div>
                @endif
            @empty
                <div class="seat-leg flex flex-wrap items-center gap-2" data-flight-id="">
                    <span class="seat-leg-label text-sm text-gray-600">Seat</span>
                    <span class="seat-label text-sm font-semibold text-navy">Not chosen</span>
                    <input type="hidden" class="passenger-seat" name="passengers[{{ $index }}][seat_id]" value="{{ $passenger['seat_id'] ?? '' }}">
                    <button type="button" class="pick-seat text-sm font-medium text-navy hover:underline">Assign on map</button>
                </div>
            @endforelse
        </div>
        <x-input-error :messages="$errors->get('passengers.'.$index.'.seat_id')" class="mt-2" />
    </div>
</div>
