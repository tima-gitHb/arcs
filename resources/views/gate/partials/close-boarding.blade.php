{{--
    Close boarding (G3: SR-12, SR-9) and confirming offloads (G4: SR-28). Expects $flight, $counts, $confirmingClose, $offload.
    Two steps on the page, no JavaScript: the button opens ?close=confirm, which shows the real form.
--}}
@if ($flight->status === \App\Enums\FlightStatus::Boarding && $confirmingClose)
    <x-panel>
        <div class="rounded-lg border p-4" style="border-color: var(--arcs-error);">
            <p class="font-medium" style="color: var(--arcs-error);">Close boarding for {{ $flight->flight_no }}?</p>
            <p class="mt-1 text-sm" style="color: var(--arcs-error);">
                {{ $counts['missing'] }} checked-in {{ \Illuminate\Support\Str::plural('passenger', $counts['missing']) }} have not boarded.
                They will become No-show and their bags will be listed for offloading. This cannot be undone.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('gate.flights.close', $flight) }}">
                    @csrf
                    <x-danger-button>Yes, close boarding</x-danger-button>
                </form>
                <a href="{{ route('gate.flights.show', $flight) }}" class="arcs-btn-secondary">Cancel</a>
            </div>
        </div>
    </x-panel>
@endif

@if (in_array($flight->status, [\App\Enums\FlightStatus::Closed, \App\Enums\FlightStatus::Departed], true))
    <x-panel id="offload" title="Bags to offload">
        @if ($offload->isNotEmpty())
            <x-slot:actions>
                <p class="text-sm font-medium text-gray-700">
                    {{ $offload->whereNotNull('offloaded_at')->count() }} of {{ $offload->count() }} bags offloaded
                </p>
            </x-slot:actions>
        @endif

        @if ($offload->isEmpty())
            <p class="text-sm text-gray-600">No bags to offload.</p>
        @else
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Tag</th>
                            <th>Weight</th>
                            <th>Type</th>
                            <th>Passenger</th>
                            <th>Seat</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($offload as $bag)
                            <tr>
                                <td class="font-mono">{{ $bag->tag_no }}</td>
                                <td>{{ $bag->weight_kg }} kg</td>
                                <td>{{ $bag->type->label() }}</td>
                                <td>{{ $bag->passenger->first_name }} {{ $bag->passenger->last_name }}</td>
                                <td class="font-mono">{{ $bag->passenger->seatAssignment?->seat->seat_no ?? '—' }}</td>
                                <td class="text-right">
                                    @if ($bag->offloaded_at)
                                        <span class="text-xs font-medium" style="color: var(--arcs-boarded);">
                                            Offloaded by {{ $bag->offloadedBy?->name }} at <x-station-time :at="$bag->offloaded_at" format="H:i" />
                                        </span>
                                    @else
                                        <form method="POST" action="{{ route('gate.bags.offload', $bag) }}">
                                            @csrf
                                            <button type="submit" class="arcs-btn-secondary !min-h-0 px-3 py-1 text-xs">
                                                Confirm offloaded
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panel>
@endif
