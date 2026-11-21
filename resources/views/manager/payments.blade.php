<x-app-layout>
    <x-page-header title="Payments" subtitle="Succeeded card charges, with failed attempts listed under the totals.">
        <button type="button" onclick="window.print()" class="no-print arcs-btn-secondary">Print</button>
    </x-page-header>

    {{-- Print styles: the browser's own print hides the header, menu, filters and the Print button. --}}
    <style>
        @media print {
            header, nav, .no-print { display: none !important; }
            body { background: #fff !important; }
            main { padding: 0 !important; }
            .print-card { box-shadow: none !important; }
        }
    </style>

    <div class="space-y-6">
        <x-manager-station :station="$station" />

        <x-panel class="no-print" title="Filters">
            <form method="GET" action="{{ route('manager.payments') }}" class="grid gap-4 sm:grid-cols-4 sm:items-start">
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
                    <x-input-error :messages="$errors->get('flight_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="user_id" value="Agent" />
                    <select id="user_id" name="user_id" class="arcs-input mt-1 block w-full">
                        <option value="">All agents</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" @selected((string) request('user_id') === (string) $member->id)>
                                {{ $member->name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('user_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="date" value="Date" />
                    <x-text-input id="date" name="date" type="date" class="mt-1 block w-full" :value="request('date')" />
                    <x-input-error :messages="$errors->get('date')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3 sm:pt-6">
                    <x-primary-button>Filter</x-primary-button>
                    <a href="{{ route('manager.payments') }}" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Clear</a>
                </div>
            </form>
        </x-panel>

        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
            @foreach (\App\Enums\PaymentKind::cases() as $kind)
                <div>
                    <span class="text-gray-500">{{ $kind->label() }}</span>
                    <span data-total="{{ $kind->value }}" class="font-medium text-gray-900">{{ \App\Support\Money::format($totals[$kind->value]) }}</span>
                </div>
            @endforeach
            <div>
                <span class="text-gray-500">Total</span>
                <span data-total="grand" class="font-medium text-gray-900">{{ \App\Support\Money::format($grandCents) }}</span>
            </div>
        </div>

        <p class="text-sm text-gray-600">Failed attempts: {{ $failedCount }} ({{ \App\Support\Money::format($failedCents) }})</p>

        <div class="print-card overflow-hidden rounded-[14px] border bg-white shadow-sm" style="border-color: var(--arcs-border);">
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
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($payments as $payment)
                            @php
                                // Booking flight wins when the payment has a reservation. Otherwise the passenger's.
                                $linked = $payment->reservation ?? $payment->passenger?->reservation;
                            @endphp
                            <tr data-payment="{{ $payment->id }}" @class(['text-red-600' => $payment->status === \App\Enums\PaymentStatus::Failed])>
                                <td class="whitespace-nowrap" data-cell="date"><x-station-time :at="$payment->created_at" format="d M Y H:i" /></td>
                                <td data-cell="flight">{{ $linked?->flight?->flight_no }}</td>
                                <td class="arcs-mono" data-cell="pnr">{{ $linked?->pnr }}</td>
                                <td data-cell="passenger">{{ $payment->passenger ? $payment->passenger->first_name.' '.$payment->passenger->last_name : 'Whole booking' }}</td>
                                <td data-cell="kind">{{ $payment->kind->label() }}</td>
                                <td class="whitespace-nowrap" data-cell="amount">{{ \App\Support\Money::format($payment->amount_cents) }}</td>
                                <td class="whitespace-nowrap" data-cell="card">{{ $payment->card_brand }} ••••{{ $payment->card_last4 }}</td>
                                <td data-cell="status">{{ $payment->status->label() }}</td>
                                <td data-cell="taken-by">{{ $payment->takenBy->name }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-gray-500">No payments.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
