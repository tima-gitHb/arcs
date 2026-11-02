<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">{{ $passenger->first_name }} {{ $passenger->last_name }}</h1>
    </x-slot>

    @php($flight = $passenger->reservation->flight)

    <div class="max-w-3xl space-y-6">
        <div class="rounded-lg bg-white p-6 shadow-sm">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-gray-500">PNR</dt>
                    <dd class="font-mono font-semibold tracking-[0.2em] text-navy">{{ $passenger->reservation->pnr }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Flight</dt>
                    <dd>{{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Departure</dt>
                    <dd>{{ $flight->departure_at->format('d M H:i') }} · Gate {{ $flight->gate ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Passenger</dt>
                    <dd>
                        {{ $passenger->type->value }}
                        @if ($passenger->accompanyingAdult)
                            (with {{ $passenger->accompanyingAdult->first_name }} {{ $passenger->accompanyingAdult->last_name }})
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500">Status</dt>
                    <dd class="font-medium">{{ $passenger->status->label() }}</dd>
                </div>
                @if ($passenger->checkedInBy)
                    <div>
                        <dt class="text-gray-500">Checked in by</dt>
                        <dd>{{ $passenger->checkedInBy->user->name }}, {{ $passenger->checkedInBy->changed_at->format('H:i') }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-navy">Travel documents</h2>

            @if ($passenger->travelDocuments->isEmpty())
                <p class="mt-2 text-sm text-gray-600">No travel documents.</p>
            @else
                <table class="mt-3 w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="py-1 font-medium">Type</th>
                            <th class="py-1 font-medium">Number</th>
                            <th class="py-1 font-medium">Country</th>
                            <th class="py-1 font-medium">Expires</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Check-in staff see full numbers (SR-34). --}}
                        @foreach ($passenger->travelDocuments as $document)
                            <tr class="border-t border-gray-100">
                                <td class="py-1.5">{{ $document->type->label() }}</td>
                                <td class="py-1.5 font-mono">{{ $document->number }}</td>
                                <td class="py-1.5">{{ $document->issuing_country }}</td>
                                <td class="py-1.5">{{ $document->expiry_date->format('d M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-600">
            Baggage, seat and boarding pass come in the next check-in tasks.
        </div>

        @if ($passenger->status === \App\Enums\PassengerStatus::Booked)
            <form method="POST" action="{{ route('checkin.passengers.confirm', $passenger) }}">
                @csrf
                <button type="submit" class="rounded bg-navy px-5 py-2.5 text-sm font-semibold text-white hover:bg-navy/90">
                    Confirm check-in
                </button>
            </form>
        @endif

        <a href="{{ route('checkin.home', ['q' => $passenger->reservation->pnr]) }}" class="inline-block text-sm font-medium text-navy hover:underline">
            ← Back to search
        </a>
    </div>
</x-app-layout>
