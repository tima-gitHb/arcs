<x-app-layout>
    <x-page-header title="Review refund" :subtitle="'PNR '.$reservation->pnr">
        <a href="{{ route('reservation.refund', $reservation) }}" class="arcs-btn-secondary">Change selection</a>
    </x-page-header>

    <div class="mx-auto max-w-3xl space-y-6">
        <x-panel title="Passengers × legs">
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Passenger</th>
                            <th>Flight</th>
                            <th>Leg</th>
                            <th>Paid</th>
                            <th>Fee</th>
                            <th>Refund</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['lines'] as $line)
                            <tr>
                                <td>{{ $line['passenger_name'] }}</td>
                                <td class="arcs-mono">{{ $line['flight_no'] }}</td>
                                <td>{{ $line['leg'] }}</td>
                                <td>{{ \App\Support\Money::format($line['paid_cents']) }}</td>
                                <td>{{ \App\Support\Money::format($line['fee_cents']) }}</td>
                                <td class="font-medium">{{ \App\Support\Money::format($line['refund_cents']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-gray-500">Paid for cancelled part</dt>
                    <dd class="text-lg font-semibold">{{ \App\Support\Money::format($preview['paid_cents']) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Cancellation fee</dt>
                    <dd class="text-lg font-semibold">{{ \App\Support\Money::format($preview['fee_cents']) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Refund</dt>
                    <dd class="text-lg font-bold" style="color: var(--arcs-success);">{{ \App\Support\Money::format($preview['refund_cents']) }}</dd>
                </div>
            </dl>

            @if ($payment)
                <p class="mt-4 text-sm text-gray-600">
                    Refund to {{ $payment->card_brand }} ••••{{ $payment->card_last4 }}
                </p>
            @endif
        </x-panel>

        <form method="POST" action="{{ route('reservation.refund.confirm', $reservation) }}">
            @csrf
            @foreach ($passengerIds as $id)
                <input type="hidden" name="passenger_ids[]" value="{{ $id }}">
            @endforeach
            @foreach ($legs as $leg)
                <input type="hidden" name="legs[]" value="{{ $leg }}">
            @endforeach

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('reservation.refund', $reservation) }}" class="arcs-btn-secondary">Back</a>
                <button type="submit" class="arcs-btn-danger">Confirm cancel and refund</button>
            </div>
        </form>
    </div>
</x-app-layout>
