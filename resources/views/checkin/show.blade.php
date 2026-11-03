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
            @php($editable = $passenger->documentsEditable())

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
                            <th class="py-1 font-medium">Verified</th>
                            <th class="py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($passenger->travelDocuments as $document)
                            <tr class="border-t border-gray-100 align-top">
                                <td class="py-2">{{ $document->type->label() }}</td>
                                {{-- SR-34: full number only for check-in staff and the Manager. --}}
                                <td class="py-2 font-mono">{{ $document->maskedNumberFor(auth()->user()) }}</td>
                                <td class="py-2">{{ $document->issuing_country }}</td>
                                <td class="py-2">{{ $document->expiry_date->format('d M Y') }}</td>
                                <td class="py-2">
                                    @if ($document->verified_at)
                                        {{ $document->verifier?->name }}, {{ $document->verified_at->format('d M H:i') }}
                                    @elseif (! $editable)
                                        <span class="text-gray-500">Not verified</span>
                                    @else
                                        <form method="POST" action="{{ route('checkin.documents.verify', $document) }}">
                                            @csrf
                                            <button type="submit" class="rounded border border-navy px-2 py-0.5 text-xs font-medium text-navy hover:bg-navy hover:text-white">
                                                Verify
                                            </button>
                                        </form>
                                    @endif
                                </td>
                                <td class="py-2 text-right">
                                    @if ($editable)
                                        <a href="{{ route('checkin.passengers.show', [$passenger, 'edit' => $document->id]) }}" class="text-xs font-medium text-navy hover:underline">
                                            Edit
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if (! $editable)
                <p class="mt-4 text-sm text-gray-600">
                    Documents can no longer be changed: this passenger is {{ strtolower($passenger->status->label()) }}.
                </p>
            @endif

            @if ($editable && $editing)
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <h3 class="font-medium text-gray-900">Edit {{ strtolower($editing->type->label()) }}</h3>
                    @include('checkin.partials.document-form', [
                        'action' => route('checkin.documents.update', $editing),
                        'method' => 'PUT',
                        'bag' => 'editDocument',
                        'document' => $editing,
                        'submit' => 'Save',
                    ])
                    <a href="{{ route('checkin.passengers.show', $passenger) }}" class="mt-2 inline-block text-sm text-gray-600 hover:underline">Cancel</a>
                </div>
            @endif

            @if ($editable)
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <h3 class="font-medium text-gray-900">Add document</h3>
                    @include('checkin.partials.document-form', [
                        'action' => route('checkin.documents.store', $passenger),
                        'method' => 'POST',
                        'bag' => 'addDocument',
                        'document' => null,
                        'submit' => 'Add document',
                    ])
                </div>
            @endif
        </div>

        @include('checkin.partials.seat-card')

        @include('checkin.partials.bags-card')

        @if ($passenger->status === \App\Enums\PassengerStatus::Booked)
            {{-- Completes check-in: StatusService::checkIn(), then the boarding pass (C5). --}}
            <form method="POST" action="{{ route('checkin.passengers.confirm', $passenger) }}">
                @csrf
                <button type="submit" class="rounded bg-navy px-5 py-2.5 text-sm font-semibold text-white hover:bg-navy/90">
                    Confirm check-in
                </button>
            </form>
        @endif

        @include('checkin.partials.boarding-pass-card')

        <a href="{{ route('checkin.home', ['q' => $passenger->reservation->pnr]) }}" class="inline-block text-sm font-medium text-navy hover:underline">
            ← Back to search
        </a>
    </div>
</x-app-layout>
