@php
    $editing = $tripFare !== null;
    $oneWay = $editing ? number_format($tripFare->one_way_cents / 100, 2, '.', '') : '';
    $roundTrip = $editing && $tripFare->round_trip_cents !== null
        ? number_format($tripFare->round_trip_cents / 100, 2, '.', '')
        : '';
@endphp
<x-app-layout>
    <x-page-header :title="$editing ? 'Edit trip fare' : 'New trip fare'" subtitle="Origin to final destination. Replaces the route sum when every passenger type has one.">
        <x-slot:breadcrumb>
            <a href="{{ route('manager.fares', ['tab' => 'trips']) }}" class="hover:underline">Fares and fees</a>
            <span class="text-gray-300">›</span>
            <span>Trip fares</span>
            <span class="text-gray-300">›</span>
            <span>{{ $editing ? 'Edit' : 'New' }}</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <x-panel>
        <form method="POST"
            action="{{ $editing ? route('manager.trip-fares.update', $tripFare) : route('manager.trip-fares.store') }}"
            class="grid max-w-3xl gap-4 sm:grid-cols-2">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <div>
                <x-input-label for="origin_code" value="Origin" />
                <select id="origin_code" name="origin_code" class="arcs-input mt-1 block w-full" required>
                    <option value="" disabled @selected(old('origin_code', $tripFare?->origin_code) === null)>Choose origin</option>
                    @foreach ($airports as $airport)
                        <option value="{{ $airport->code }}" @selected(old('origin_code', $tripFare?->origin_code) === $airport->code)>
                            {{ $airport->code }} · {{ $airport->city }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('origin_code')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="destination_code" value="Final destination" />
                <select id="destination_code" name="destination_code" class="arcs-input mt-1 block w-full" required>
                    <option value="" disabled @selected(old('destination_code', $tripFare?->destination_code) === null)>Choose destination</option>
                    @foreach ($airports as $airport)
                        <option value="{{ $airport->code }}" @selected(old('destination_code', $tripFare?->destination_code) === $airport->code)>
                            {{ $airport->code }} · {{ $airport->city }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">Must differ from origin (at least two airports on the journey).</p>
                <x-input-error :messages="$errors->get('destination_code')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="cabin_class" value="Cabin" />
                <select id="cabin_class" name="cabin_class" class="arcs-input mt-1 block w-full" required>
                    @foreach ($cabins as $cabin)
                        <option value="{{ $cabin->value }}" @selected(old('cabin_class', $tripFare?->cabin_class?->value) === $cabin->value)>{{ $cabin->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('cabin_class')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="passenger_type" value="Passenger type" />
                <select id="passenger_type" name="passenger_type" class="arcs-input mt-1 block w-full" required>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('passenger_type', $tripFare?->passenger_type?->value) === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('passenger_type')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="one_way" value="One-way amount (USD)" />
                <div class="mt-1 flex items-center gap-1">
                    <span class="text-gray-600">$</span>
                    <x-text-input id="one_way" name="one_way" type="number" min="0.01" max="99999.99" step="0.01" class="w-36"
                        :value="old('one_way', $oneWay)" required />
                </div>
                <x-input-error :messages="$errors->get('one_way')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="round_trip" value="Round-trip amount (USD, optional)" />
                <div class="mt-1 flex items-center gap-1">
                    <span class="text-gray-600">$</span>
                    <x-text-input id="round_trip" name="round_trip" type="number" min="0.01" max="99999.99" step="0.01" class="w-36"
                        :value="old('round_trip', $roundTrip)" />
                </div>
                <x-input-error :messages="$errors->get('round_trip')" class="mt-2" />
            </div>

            <div class="sm:col-span-2 flex flex-wrap gap-3 pt-2">
                <a href="{{ route('manager.fares', ['tab' => 'trips']) }}" class="arcs-btn-secondary">Cancel</a>
                <x-primary-button>{{ $editing ? 'Save trip fare' : 'Create trip fare' }}</x-primary-button>
            </div>
        </form>
    </x-panel>
</x-app-layout>
