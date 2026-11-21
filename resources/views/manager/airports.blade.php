<x-app-layout>
    <x-page-header title="Airports" subtitle="Airports of the network. The code cannot be changed once the airport is added." />

    <div class="space-y-6">
        <x-panel title="New airport">
            <form method="POST" action="{{ route('manager.airports.store') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 sm:items-start">
                @csrf

                <div>
                    <x-input-label for="code" value="Code" />
                    <x-text-input id="code" name="code" type="text" maxlength="3" class="mt-1 block w-full uppercase" :value="old('code')" required />
                    <x-input-error :messages="$errors->get('code')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" maxlength="100" class="mt-1 block w-full" :value="old('name')" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="city" value="City" />
                    <x-text-input id="city" name="city" type="text" maxlength="80" class="mt-1 block w-full" :value="old('city')" required />
                    <x-input-error :messages="$errors->get('city')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="country" value="Country" />
                    <x-country-select id="country" name="country" :selected="old('country')" />
                    <x-input-error :messages="$errors->get('country')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="timezone" value="Time zone" />
                    <select id="timezone" name="timezone" required class="arcs-input mt-1 block w-full">
                        <option value="" disabled @selected(old('timezone') === null)>Choose a time zone</option>
                        @foreach ($timezones as $zone)
                            <option value="{{ $zone }}" @selected(old('timezone') === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="min_connection_minutes" value="Minimum connection (minutes)" />
                    <x-text-input id="min_connection_minutes" name="min_connection_minutes" type="number" min="0" max="1440" class="mt-1 block w-full" :value="old('min_connection_minutes', 60)" required />
                    <x-input-error :messages="$errors->get('min_connection_minutes')" class="mt-2" />
                </div>

                <div class="sm:pt-6">
                    <x-primary-button>Add airport</x-primary-button>
                </div>
            </form>
        </x-panel>

        <x-panel title="Network">
            <div class="hidden lg:grid lg:grid-cols-7 gap-3 border-b pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500" style="border-color: var(--arcs-border);">
                <span>Code</span>
                <span>Name</span>
                <span>City</span>
                <span>Country</span>
                <span>Time zone</span>
                <span>Minimum connection (minutes)</span>
            </div>
            @foreach ($airports as $airport)
                <form method="POST" action="{{ route('manager.airports.update', $airport) }}" class="grid gap-3 border-b py-3 last:border-0 lg:grid-cols-7 lg:items-start" style="border-color: var(--arcs-border);">
                    @csrf
                    @method('PUT')
                    <p class="pt-2 font-medium text-gray-900 arcs-mono">{{ $airport->code }}</p>
                    <x-text-input :id="'name-'.$airport->code" name="name" type="text" maxlength="100" class="block w-full" :value="$airport->name" required />
                    <x-text-input :id="'city-'.$airport->code" name="city" type="text" maxlength="80" class="block w-full" :value="$airport->city" required />
                    <x-country-select :id="'country-'.$airport->code" name="country" :selected="$airport->country" />
                    <select id="timezone-{{ $airport->code }}" name="timezone" required class="arcs-input block w-full">
                        @foreach ($timezones as $zone)
                            <option value="{{ $zone }}" @selected($airport->timezone === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                    <x-text-input :id="'minutes-'.$airport->code" name="min_connection_minutes" type="number" min="0" max="1440" class="block w-full" :value="$airport->min_connection_minutes" required />
                    <div>
                        <x-primary-button>Save</x-primary-button>
                    </div>
                </form>
            @endforeach
        </x-panel>
    </div>
</x-app-layout>
