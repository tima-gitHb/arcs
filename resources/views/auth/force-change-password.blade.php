<x-guest-layout>
    <h1 class="text-[22px] font-bold tracking-tight text-gray-900">Choose a new password</h1>
    <p class="mt-1 text-sm text-gray-500">At least 8 characters, with letters and numbers. You must set a new password before you can use ARCS.</p>

    <form method="POST" action="{{ route('password.force.update') }}" class="mt-6">
        @csrf
        @method('PUT')

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autofocus autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" class="mt-1 block w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="mt-6 flex justify-end">
            <x-primary-button>
                {{ __('Save password') }}
            </x-primary-button>
        </div>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4">
        @csrf
        <button type="submit" class="text-sm text-gray-600 underline">Log out</button>
    </form>
</x-guest-layout>
