<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Welcome, {{ $role->label() }}</h1>
    </x-slot>

    <div class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
        Your pages will appear here.
    </div>
</x-app-layout>
