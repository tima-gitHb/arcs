<x-app-layout>
    <x-page-header title="Profile" subtitle="Your name, email, and password." />

    <div class="max-w-2xl space-y-6">
        <x-panel>
            @include('profile.partials.update-profile-information-form')
        </x-panel>

        <x-panel>
            @include('profile.partials.update-password-form')
        </x-panel>

        <x-panel>
            @include('profile.partials.delete-user-form')
        </x-panel>
    </div>
</x-app-layout>
