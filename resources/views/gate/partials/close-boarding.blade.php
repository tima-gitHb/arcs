{{--
    Close boarding (G3: SR-12, SR-9) and confirming offloads (G4: SR-28). Expects $flight, $counts, $confirmingClose, $offload.
    Two steps on the page, no JavaScript: the button opens ?close=confirm, which shows the real form.
--}}
@if ($flight->status === \App\Enums\FlightStatus::Boarding)
    <div class="rounded-lg bg-white p-6 shadow-sm">
        @if ($confirmingClose)
            <div class="rounded border border-red-200 bg-red-50 p-4">
                <p class="font-medium text-red-900">Close boarding for {{ $flight->flight_no }}?</p>
                <p class="mt-1 text-sm text-red-800">
                    {{ $counts['missing'] }} checked-in {{ \Illuminate\Support\Str::plural('passenger', $counts['missing']) }} have not boarded.
                    They will become No-show and their bags will be listed for offloading. This cannot be undone.
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('gate.flights.close', $flight) }}">
                        @csrf
                        <button type="submit" class="rounded bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">
                            Yes, close boarding
                        </button>
                    </form>
                    <a href="{{ route('gate.flights.show', $flight) }}" class="text-sm font-medium text-gray-700 hover:underline">Cancel</a>
                </div>
            </div>
        @else
            <a href="{{ route('gate.flights.show', [$flight, 'close' => 'confirm']) }}"
               class="inline-block rounded border border-red-700 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-700 hover:text-white">
                Close boarding
            </a>
        @endif
    </div>
@endif

@if (in_array($flight->status, [\App\Enums\FlightStatus::Closed, \App\Enums\FlightStatus::Departed], true))
    <section id="offload" class="rounded-lg bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="text-lg font-semibold text-navy">Bags to offload</h2>
            @if ($offload->isNotEmpty())
                <p class="text-sm font-medium text-gray-700">
                    {{ $offload->whereNotNull('offloaded_at')->count() }} of {{ $offload->count() }} bags offloaded
                </p>
            @endif
        </div>

        @if ($offload->isEmpty())
            <p class="mt-2 text-sm text-gray-600">No bags to offload.</p>
        @else
            <table class="mt-3 w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr>
                        <th class="py-1 font-medium">Tag</th>
                        <th class="py-1 font-medium">Weight</th>
                        <th class="py-1 font-medium">Type</th>
                        <th class="py-1 font-medium">Passenger</th>
                        <th class="py-1 font-medium">Seat</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($offload as $bag)
                        <tr class="border-t border-gray-100">
                            <td class="py-2 font-mono">{{ $bag->tag_no }}</td>
                            <td class="py-2">{{ $bag->weight_kg }} kg</td>
                            <td class="py-2">{{ $bag->type->label() }}</td>
                            <td class="py-2">{{ $bag->passenger->first_name }} {{ $bag->passenger->last_name }}</td>
                            <td class="py-2 font-mono">{{ $bag->passenger->seatAssignment?->seat->seat_no ?? '—' }}</td>
                            <td class="py-2 text-right">
                                @if ($bag->offloaded_at)
                                    <span class="text-xs font-medium text-green-800">
                                        Offloaded by {{ $bag->offloadedBy?->name }} at {{ $bag->offloaded_at->format('H:i') }}
                                    </span>
                                @else
                                    <form method="POST" action="{{ route('gate.bags.offload', $bag) }}">
                                        @csrf
                                        <button type="submit" class="rounded border border-navy px-2 py-1 text-xs font-medium text-navy hover:bg-navy hover:text-white">
                                            Confirm offloaded
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endif
