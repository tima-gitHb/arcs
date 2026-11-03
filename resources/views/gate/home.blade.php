<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Manifest</h1>
        <p class="mt-1 text-sm text-gray-600">Open a flight to see its live manifest and board passengers.</p>
    </x-slot>

    <x-todays-flights :role="'gate'" />
</x-app-layout>
