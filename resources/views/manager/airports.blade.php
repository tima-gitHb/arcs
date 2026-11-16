<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Airports</h1>
    </x-slot>

    <div class="space-y-6">
        <div class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
            <h2 class="text-lg font-semibold text-navy">New airport</h2>
            <p class="mt-1 text-sm text-gray-600">The code cannot be changed once the airport is added.</p>

            <form method="POST" action="{{ route('manager.airports.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 sm:items-start">
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
                    <select id="timezone" name="timezone" required class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
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
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-x-auto text-sm">
            <div class="hidden lg:grid lg:grid-cols-7 gap-3 px-4 py-2 text-gray-500 border-b border-gray-200">
                <span class="font-medium">Code</span>
                <span class="font-medium">Name</span>
                <span class="font-medium">City</span>
                <span class="font-medium">Country</span>
                <span class="font-medium">Time zone</span>
                <span class="font-medium">Minimum connection (minutes)</span>
            </div>
            @foreach ($airports as $airport)
                <form method="POST" action="{{ route('manager.airports.update', $airport) }}" class="grid gap-3 border-b border-gray-100 px-4 py-3 last:border-0 lg:grid-cols-7 lg:items-start">
                    @csrf
                    @method('PUT')
                    <p class="font-medium text-gray-900 pt-2">{{ $airport->code }}</p>
                    <x-text-input :id="'name-'.$airport->code" name="name" type="text" maxlength="100" class="block w-full" :value="$airport->name" required />
                    <x-text-input :id="'city-'.$airport->code" name="city" type="text" maxlength="80" class="block w-full" :value="$airport->city" required />
                    <x-country-select :id="'country-'.$airport->code" name="country" :selected="$airport->country" />
                    <select id="timezone-{{ $airport->code }}" name="timezone" required class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
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
        </div>
    </div>
</x-app-layout>
