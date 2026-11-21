<x-app-layout>
    <x-page-header title="New account" subtitle="The person gets a welcome email with a link to choose their password.">
        <x-slot:breadcrumb>
            <span class="text-gray-400">Staff accounts</span>
            <span class="text-gray-300">›</span>
            <a href="{{ route('manager.accounts') }}" class="hover:underline">Accounts</a>
            <span class="text-gray-300">›</span>
            <span>New account</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <x-panel>
        <form method="POST" action="{{ route('manager.accounts.store') }}" class="grid max-w-3xl gap-4 sm:grid-cols-2">
            @csrf
            <div>
                <x-input-label for="name" value="Name" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="email" value="Email" />
                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="role" value="Role" />
                <select id="role" name="role" class="arcs-input mt-1 block w-full" required>
                    <option value="" disabled @selected(! old('role'))>Choose a role</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('role')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="airport_code" value="Station" />
                <select id="airport_code" name="airport_code" class="arcs-input mt-1 block w-full">
                    <option value="" @selected(old('airport_code') === null)>—</option>
                    @foreach ($airports as $airport)
                        <option value="{{ $airport->code }}" @selected(old('airport_code') === $airport->code)>{{ $airport->code }} · {{ $airport->city }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-sm text-gray-600">Required for check-in and gate staff</p>
                <x-input-error :messages="$errors->get('airport_code')" class="mt-2" />
            </div>
            <div class="sm:col-span-2 flex flex-wrap gap-3">
                <a href="{{ route('manager.accounts') }}" class="arcs-btn-secondary">Cancel</a>
                <x-primary-button>Create account</x-primary-button>
            </div>
        </form>
    </x-panel>
</x-app-layout>
