<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy print:hidden">PNR confirmed</h1>
    </x-slot>

    <div class="max-w-3xl">
        @if (session('success'))
            <div class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 print:hidden" role="status">
                {{ session('success') }}
                <span class="mt-1 block text-green-700">Read the PNR back to the caller, then print or email the receipt.</span>
            </div>
        @endif

        <div class="rounded-lg bg-white p-6 shadow-sm">
            @include('reservation.partials.eticket', [
                'reservation' => $reservation,
                'payment' => $payment,
            ])
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-4 print:hidden">
            <button
                type="button"
                onclick="window.print()"
                class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90"
            >
                Print receipt
            </button>
            <a href="{{ route('reservation.create') }}" class="text-sm font-medium text-navy hover:underline">
                Next booking
            </a>
            <a href="{{ route('reservation.search', ['pnr' => $reservation->pnr]) }}" class="text-sm font-medium text-navy hover:underline">
                Open in find reservation
            </a>
        </div>
    </div>
</x-app-layout>
