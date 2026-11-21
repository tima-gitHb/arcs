@php $editing = $airport !== null; @endphp
<x-app-layout>
    <x-page-header :title="$editing ? 'Edit airport' : 'New airport'" :subtitle="$editing ? 'The code stays '.$airport->code.'.' : 'Add an airport to the network.'">
        <x-slot:breadcrumb>
            <a href="{{ route('manager.airports') }}" class="hover:underline">Airports</a>
            <span class="text-gray-300">›</span>
            <span>{{ $editing ? $airport->code : 'New airport' }}</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <x-panel>
        <form method="POST" action="{{ $editing ? route('manager.airports.update', $airport) : route('manager.airports.store') }}" class="grid max-w-3xl gap-4 sm:grid-cols-2">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <div>
                <x-input-label for="code" value="Code" />
                @if ($editing)
                    <p class="mt-2 arcs-mono text-lg font-semibold">{{ $airport->code }}</p>
                @else
                    <x-text-input id="code" name="code" type="text" maxlength="3" class="mt-1 block w-full uppercase" :value="old('code')" required />
                    <x-input-error :messages="$errors->get('code')" class="mt-2" />
                @endif
            </div>

            <div>
                <x-input-label for="name" value="Name" />
                <x-text-input id="name" name="name" type="text" maxlength="100" class="mt-1 block w-full" :value="old('name', $airport?->name)" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="city" value="City" />
                <x-text-input id="city" name="city" type="text" maxlength="80" class="mt-1 block w-full" :value="old('city', $airport?->city)" required />
                <x-input-error :messages="$errors->get('city')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="country" value="Country" />
                <x-country-select id="country" name="country" :selected="old('country', $airport?->country)" simple />
                <x-input-error :messages="$errors->get('country')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="timezone" value="Time zone" />
                <select id="timezone" name="timezone" required class="arcs-input mt-1 block w-full">
                    <option value="" disabled @selected(old('timezone', $airport?->timezone) === null)>Choose a time zone</option>
                    @foreach ($timezones as $zone)
                        <option value="{{ $zone }}" @selected(old('timezone', $airport?->timezone) === $zone)>{{ $zone }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="min_connection_minutes" value="Minimum connection (minutes)" />
                <x-text-input id="min_connection_minutes" name="min_connection_minutes" type="number" min="0" max="1440" class="mt-1 block w-full" :value="old('min_connection_minutes', $airport?->min_connection_minutes ?? 60)" required />
                <x-input-error :messages="$errors->get('min_connection_minutes')" class="mt-2" />
            </div>

            <div class="sm:col-span-2 flex flex-wrap gap-3 pt-2">
                <a href="{{ route('manager.airports') }}" class="arcs-btn-secondary">Cancel</a>
                <x-primary-button>{{ $editing ? 'Save airport' : 'Add airport' }}</x-primary-button>
            </div>
        </form>
    </x-panel>
</x-app-layout>
