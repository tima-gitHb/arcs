<x-app-layout>
    <x-page-header title="Payments" subtitle="Succeeded card charges, with failed attempts listed under the totals.">
        <x-slot:breadcrumb>
            <span>Payments</span>
        </x-slot:breadcrumb>
        <button type="button" onclick="window.print()" class="no-print arcs-btn-secondary">Print</button>
    </x-page-header>

    <style>
        @media print {
            header, nav, .no-print, aside { display: none !important; }
            body { background: #fff !important; }
            main { padding: 0 !important; }
            .print-card { box-shadow: none !important; }
        }
    </style>

    <div class="space-y-6">
        <x-panel class="no-print" title="Filters">
            <form method="GET" action="{{ route('manager.payments') }}" class="grid gap-4 sm:grid-cols-5 sm:items-start">
                <div>
                    <x-input-label for="flight_id" value="Flight" />
                    <select id="flight_id" name="flight_id" class="arcs-input mt-1 block w-full">
                        <option value="">All flights</option>
                        @foreach ($flights as $flight)
                            <option value="{{ $flight->id }}" @selected((string) request('flight_id') === (string) $flight->id)>
                                {{ $flight->flight_no }} — {{ $flight->departureLocal()->format('d M Y') }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="user_id" value="Agent" />
                    <select id="user_id" name="user_id" class="arcs-input mt-1 block w-full">
                        <option value="">All agents</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" @selected((string) request('user_id') === (string) $member->id)>{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="date" value="Date" />
                    <x-text-input id="date" name="date" type="date" class="mt-1 block w-full" :value="request('date')" />
                </div>
                <div>
                    <x-input-label for="status" value="Status" />
                    <select id="status" name="status" class="arcs-input mt-1 block w-full">
                        <option value="" @selected(($statusFilter ?? '') === '')>All</option>
                        <option value="paid" @selected(($statusFilter ?? '') === 'paid')>Paid</option>
                        <option value="refunded" @selected(($statusFilter ?? '') === 'refunded')>Refunded</option>
                        <option value="failed" @selected(($statusFilter ?? '') === 'failed')>Failed</option>
                    </select>
                </div>
                <div class="flex items-center gap-3 sm:pt-6">
                    <x-primary-button>Filter</x-primary-button>
                    <a href="{{ route('manager.payments') }}" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Clear</a>
                </div>
            </form>
        </x-panel>

        <div class="flex flex-wrap gap-2 text-sm">
            @foreach (\App\Enums\PaymentKind::cases() as $kind)
                <span class="rounded-full px-3 py-1.5" style="background: #EEF0F4;">
                    <span class="text-gray-500">{{ $kind->label() }}</span>
                    <span data-total="{{ $kind->value }}" class="ml-1 font-medium text-gray-900">{{ \App\Support\Money::format($totals[$kind->value]) }}</span>
                </span>
            @endforeach
            <span class="rounded-full px-3 py-1.5" style="background: #EEF0F4;">
                <span class="text-gray-500">Net total</span>
                <span data-total="grand" class="ml-1 font-medium text-gray-900">{{ \App\Support\Money::format($grandCents) }}</span>
            </span>
            <span class="rounded-full px-3 py-1.5" style="background: #FCE8E6;">
                Failed attempts: {{ $failedCount }} ({{ \App\Support\Money::format($failedCents) }})
            </span>
        </div>
        <p class="sr-only">Failed attempts: {{ $failedCount }} ({{ \App\Support\Money::format($failedCents) }})</p>

        <div class="print-card overflow-hidden rounded-[22px] border bg-white shadow-sm" style="border-color: var(--arcs-border);">
            <div class="arcs-table-wrap">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Flight</th>
                            <th>PNR</th>
                            <th>Passenger</th>
                            <th>Kind</th>
                            <th>Amount</th>
                            <th>Card</th>
                            <th>Status</th>
                            <th>Taken by</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($payments as $payment)
                            @php
                                $linked = $payment->reservation ?? $payment->passenger?->reservation;
                                $paid = $payment->status === \App\Enums\PaymentStatus::Succeeded;
                                $isRefund = $payment->isRefund();
                                $badgeBg = $paid ? '#E6F4EA' : ($isRefund ? '#E8F0FE' : '#FCE8E6');
                                $badgeColor = $paid ? 'var(--arcs-success)' : ($isRefund ? 'var(--arcs-accent-text)' : 'var(--arcs-error)');
                            @endphp
                            <tr data-payment="{{ $payment->id }}" @class(['text-red-600' => ! $paid && ! $isRefund])>
                                <td class="whitespace-nowrap" data-cell="date"><x-station-time :at="$payment->created_at" format="d M Y H:i" /></td>
                                <td data-cell="flight">{{ $linked?->flight?->flight_no }}</td>
                                <td class="arcs-mono" data-cell="pnr">{{ $linked?->pnr }}</td>
                                <td data-cell="passenger">{{ $payment->passenger ? $payment->passenger->first_name.' '.$payment->passenger->last_name : 'Whole booking' }}</td>
                                <td data-cell="kind">{{ $payment->kind->label() }}</td>
                                <td class="whitespace-nowrap" data-cell="amount">
                                    @if ($isRefund)-@endif{{ \App\Support\Money::format($payment->amount_cents) }}
                                </td>
                                <td class="whitespace-nowrap" data-cell="card">{{ $payment->card_brand }} ••••{{ $payment->card_last4 }}</td>
                                <td data-cell="status">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" style="background: {{ $badgeBg }}; color: {{ $badgeColor }};">
                                        {{ $payment->status->label() }}
                                    </span>
                                </td>
                                <td data-cell="taken-by">{{ $payment->takenBy->name }}</td>
                                <td class="text-right">
                                    <a href="{{ route('manager.payments.show', $payment) }}" class="font-medium hover:underline no-print" style="color: var(--arcs-accent-text);">Open →</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-gray-500">No payments.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
