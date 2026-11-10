<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Payments</h1>
    </x-slot>

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
        <form method="GET" action="{{ route('manager.payments') }}" class="no-print bg-white rounded-lg shadow-sm p-6 grid gap-4 sm:grid-cols-4 sm:items-start">
            <div>
                <x-input-label for="flight_id" value="Flight" />
                <select id="flight_id" name="flight_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">All flights</option>
                    @foreach ($flights as $flight)
                        <option value="{{ $flight->id }}" @selected((string) request('flight_id') === (string) $flight->id)>
                            {{ $flight->flight_no }} — {{ $flight->departure_at->format('d M Y') }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('flight_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="user_id" value="Agent" />
                <select id="user_id" name="user_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
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
                <a href="{{ route('manager.payments') }}" class="text-sm text-indigo-600 hover:underline">Clear</a>
            </div>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                @foreach (\App\Enums\PaymentKind::cases() as $kind)
                    <div>
                        <span class="text-gray-500">{{ $kind->label() }}</span>
                        <span data-total="{{ $kind->value }}" class="font-medium text-navy">{{ \App\Support\Money::format($totals[$kind->value]) }}</span>
                    </div>
                @endforeach
                <div>
                    <span class="text-gray-500">Total</span>
                    <span data-total="grand" class="font-medium text-navy">{{ \App\Support\Money::format($grandCents) }}</span>
                </div>
            </div>

            <button type="button" onclick="window.print()" class="no-print rounded border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Print</button>
        </div>

        <p class="text-sm text-gray-600">Failed attempts: {{ $failedCount }} ({{ \App\Support\Money::format($failedCents) }})</p>

        <div class="print-card bg-white rounded-lg shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-2 font-medium">Date</th>
                        <th class="px-4 py-2 font-medium">Flight</th>
                        <th class="px-4 py-2 font-medium">PNR</th>
                        <th class="px-4 py-2 font-medium">Passenger</th>
                        <th class="px-4 py-2 font-medium">Kind</th>
                        <th class="px-4 py-2 font-medium">Amount</th>
                        <th class="px-4 py-2 font-medium">Card</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Taken by</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payments as $payment)
                        @php
                            // Booking flight wins when the payment has a reservation. Otherwise the passenger's.
                            $linked = $payment->reservation ?? $payment->passenger?->reservation;
                        @endphp
                        <tr data-payment="{{ $payment->id }}" @class(['border-b border-gray-100 last:border-0', 'text-red-600' => $payment->status === \App\Enums\PaymentStatus::Failed])>
                            <td class="px-4 py-2 whitespace-nowrap" data-cell="date">{{ $payment->created_at->format('d M Y H:i') }}</td>
                            <td class="px-4 py-2" data-cell="flight">{{ $linked?->flight?->flight_no }}</td>
                            <td class="px-4 py-2" data-cell="pnr">{{ $linked?->pnr }}</td>
                            <td class="px-4 py-2" data-cell="passenger">{{ $payment->passenger ? $payment->passenger->first_name.' '.$payment->passenger->last_name : 'Whole booking' }}</td>
                            <td class="px-4 py-2" data-cell="kind">{{ $payment->kind->label() }}</td>
                            <td class="px-4 py-2 whitespace-nowrap" data-cell="amount">{{ \App\Support\Money::format($payment->amount_cents) }}</td>
                            <td class="px-4 py-2 whitespace-nowrap" data-cell="card">{{ $payment->card_brand }} ••••{{ $payment->card_last4 }}</td>
                            <td class="px-4 py-2" data-cell="status">{{ $payment->status->label() }}</td>
                            <td class="px-4 py-2" data-cell="taken-by">{{ $payment->takenBy->name }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-6 text-center text-gray-500">No payments.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
