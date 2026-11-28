@php
    $linked = $reservation;
@endphp
<x-app-layout>
    <x-page-header title="Payment" :subtitle="\App\Support\Money::format($payment->amount_cents).' · '.$payment->status->label()">
        <x-slot:breadcrumb>
            <a href="{{ route('manager.payments') }}" class="hover:underline">Payments</a>
            <span class="text-gray-300">›</span>
            <span>#{{ $payment->id }}</span>
        </x-slot:breadcrumb>
        <button type="button" onclick="window.print()" class="no-print arcs-btn-secondary">Print</button>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <div class="space-y-6">
            <x-panel title="Charge">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Date</dt>
                        <dd class="font-medium"><x-station-time :at="$payment->created_at" format="d M Y H:i" /></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Taken by</dt>
                        <dd class="font-medium">
                            @if ($payment->takenBy)
                                <a href="{{ route('manager.accounts.show', $payment->takenBy) }}" class="hover:underline" style="color: var(--arcs-accent-text);">{{ $payment->takenBy->name }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Card</dt>
                        <dd class="font-medium">{{ $payment->card_brand }} ••••{{ $payment->card_last4 }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Reference</dt>
                        <dd class="font-medium arcs-mono">{{ $payment->gateway_reference ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Kind</dt>
                        <dd class="font-medium">{{ $payment->kind->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Total</dt>
                        <dd class="text-lg font-bold">
                            @if ($payment->isRefund())−@endif{{ \App\Support\Money::format($payment->amount_cents) }}
                        </dd>
                    </div>
                    @if ($payment->refundOf)
                        <div class="sm:col-span-2">
                            <dt class="text-gray-500">Original payment</dt>
                            <dd class="font-medium">
                                <a href="{{ route('manager.payments.show', $payment->refundOf) }}" class="hover:underline" style="color: var(--arcs-accent-text);">
                                    #{{ $payment->refundOf->id }} · {{ \App\Support\Money::format($payment->refundOf->amount_cents) }}
                                    · {{ $payment->refundOf->status->label() }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    @if ($payment->refunds->isNotEmpty())
                        <div class="sm:col-span-2">
                            <dt class="text-gray-500">Refunds</dt>
                            <dd class="space-y-1 font-medium">
                                @foreach ($payment->refunds as $refundRow)
                                    <div>
                                        <a href="{{ route('manager.payments.show', $refundRow) }}" class="hover:underline" style="color: var(--arcs-accent-text);">
                                            #{{ $refundRow->id }} · {{ \App\Support\Money::format($refundRow->amount_cents) }}
                                        </a>
                                        <span class="ml-2 rounded-full px-2 py-0.5 text-xs font-semibold" style="background: #E8F0FE;">Refunded</span>
                                    </div>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-panel>

            <x-panel title="All attempts for this booking">
                <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                    <table class="arcs-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Kind</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Taken by</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($attempts as $attempt)
                                <tr @class(['font-semibold' => $attempt->id === $payment->id])>
                                    <td><x-station-time :at="$attempt->created_at" format="d M Y H:i" /></td>
                                    <td>{{ $attempt->kind->label() }}</td>
                                    <td>{{ \App\Support\Money::format($attempt->amount_cents) }}</td>
                                    <td>{{ $attempt->status->label() }}</td>
                                    <td>{{ $attempt->takenBy?->name }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-gray-500">No other attempts.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-panel>
        </div>

        <x-panel title="Booking">
            @if ($linked)
                <p class="arcs-mono text-lg font-bold">{{ $linked->pnr }}</p>
                <p class="mt-1 text-sm text-gray-600">{{ $linked->flight?->flight_no }} · {{ $linked->flight?->origin }} → {{ $linked->flight?->destination }}</p>
                <ul class="mt-4 space-y-1 text-sm text-gray-700">
                    @foreach ($linked->passengers as $passenger)
                        <li>{{ $passenger->first_name }} {{ $passenger->last_name }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-gray-500">No booking linked.</p>
            @endif
        </x-panel>
    </div>
</x-app-layout>
