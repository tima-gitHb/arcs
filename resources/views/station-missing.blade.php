{{-- Shown to check-in and gate staff whose account has no station (B13a). --}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">No station set</h1>
    </x-slot>

    <div class="max-w-xl rounded-lg bg-white p-6 shadow-sm" role="alert">
        <p class="text-gray-800">No station set: ask your manager.</p>
        <p class="mt-2 text-sm text-gray-600">
            Check-in and gate work is done for the flights departing from your airport.
            A manager sets your airport on your account.
        </p>
    </div>
</x-app-layout>
