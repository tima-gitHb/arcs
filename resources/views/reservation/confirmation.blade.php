<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Reservation created</h1>
    </x-slot>

    <div class="max-w-3xl">
        @include('reservation.partials.summary', ['reservation' => $reservation])

        <a href="{{ route('reservation.create') }}" class="mt-6 inline-block text-sm font-medium text-navy hover:underline">
            Create another reservation
        </a>
    </div>
</x-app-layout>
