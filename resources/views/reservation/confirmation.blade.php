<x-app-layout>
    <x-page-header title="Booking confirmed" subtitle="Read the PNR back to the caller, then print or email the receipt." />

    <div class="mx-auto max-w-4xl space-y-6">
        @if (session('success'))
            <div class="arcs-card px-4 py-3 text-sm print:hidden" style="background: var(--arcs-success-bg); color: var(--arcs-success);" role="status">
                {{ session('success') }}
                <span class="mt-1 block">Read the PNR back to the caller, then print or email the receipt.</span>
            </div>
        @endif

        <x-panel class="print:shadow-none">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-4">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full text-2xl font-bold text-white" style="background: var(--arcs-boarded);" aria-hidden="true">✓</span>
                    <div>
                        <h2 class="text-[26px] font-bold text-gray-900">
                            Booking confirmed
                            @if ($reservation->isGroup())
                                <span class="ml-2 align-middle rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-white" style="background: var(--arcs-navy);">Group</span>
                            @endif
                        </h2>
                        <p class="mt-1 text-sm text-gray-500">
                            @if ($payment)
                                Paid with {{ $payment->card_brand }} ••••{{ $payment->card_last4 }}
                                @if ($reservation->contact_email)
                                    · e-ticket emailed to the booking contact.
                                @endif
                            @else
                                PNR confirmed.
                            @endif
                        </p>
                    </div>
                </div>
                <div class="rounded-[18px] px-5 py-3 text-center" style="background: var(--arcs-track);">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">PNR</p>
                    <p class="arcs-mono mt-1 text-3xl font-bold tracking-[0.2em]" data-pnr>{{ $reservation->pnr }}</p>
                </div>
            </div>
        </x-panel>

        <x-panel class="print:shadow-none">
            @include('reservation.partials.eticket', [
                'reservation' => $reservation,
                'payment' => $payment,
                'journeyQuote' => $journeyQuote,
            ])
        </x-panel>

        <div class="flex flex-wrap items-center gap-3 print:hidden">
            <button type="button" onclick="window.print()" class="arcs-btn-secondary">
                Download PDF
            </button>
            <a href="{{ route('reservation.search', ['pnr' => $reservation->pnr]) }}" class="arcs-btn-secondary">
                Open booking
            </a>
            <a href="{{ route('reservation.create') }}" class="arcs-btn-primary ml-auto">
                New reservation
            </a>
        </div>

        <div class="flex flex-wrap gap-3 print:hidden">
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
