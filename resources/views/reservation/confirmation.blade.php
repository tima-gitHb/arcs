<x-app-layout>
    <x-slot name="header">
        <x-page-header title="PNR confirmed" subtitle="Read the PNR back to the caller, then print or email the receipt." class="print:hidden">
            <a href="{{ route('reservation.create') }}" class="arcs-btn-secondary">Next booking</a>
            <a href="{{ route('reservation.search', ['pnr' => $reservation->pnr]) }}" class="arcs-btn-secondary">Open in find reservation</a>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-6">
        @if (session('success'))
            <div class="rounded-[14px] border px-4 py-3 text-sm print:hidden" style="border-color: var(--arcs-success); background: var(--arcs-success-bg); color: var(--arcs-success);" role="status">
                {{ session('success') }}
                <span class="mt-1 block">Read the PNR back to the caller, then print or email the receipt.</span>
            </div>
        @endif

        <x-panel class="print:border-0 print:shadow-none">
            @include('reservation.partials.eticket', [
                'reservation' => $reservation,
                'payment' => $payment,
                'journeyQuote' => $journeyQuote,
            ])
        </x-panel>

        <div class="flex flex-wrap items-center gap-3 print:hidden">
            <button type="button" onclick="window.print()" class="arcs-btn-primary">
                Print receipt
            </button>
            <a href="{{ route('reservation.create') }}" class="arcs-btn-secondary">
                Next booking
            </a>
            <a href="{{ route('reservation.search', ['pnr' => $reservation->pnr]) }}" class="arcs-btn-secondary">
                Open in find reservation
            </a>
        </div>
    </div>
</x-app-layout>
