{{-- Boarding panel (G2, SR-10): Start boarding, then the PNR box while the flight is Boarding.
     Expects $flight, $choosing, $choosingPnr, $confirmingStart. --}}
<div class="rounded-lg bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-navy">Board a passenger</h2>

    @if ($flight->status === \App\Enums\FlightStatus::Boarding)
        <form method="POST" action="{{ route('gate.flights.board', $flight) }}" class="mt-3 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <x-input-label for="pnr" value="PNR on the boarding pass" />
                <x-text-input id="pnr" name="pnr" type="text" maxlength="6" class="mt-1 w-40 font-mono uppercase tracking-[0.2em]"
                    autocomplete="off" autofocus required />
            </div>
            <button type="submit" class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">Board</button>
        </form>

        @if ($choosing && $choosing->count() > 1)
            <div class="mt-4 rounded border border-navy/30 bg-sky-50 p-4">
                <p class="text-sm font-medium text-navy">Choose the passenger for PNR {{ $choosingPnr }}</p>
                <ul class="mt-2 divide-y divide-sky-100">
                    @foreach ($choosing as $candidate)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                            <span>
                                {{ $candidate->first_name }} {{ $candidate->last_name }}
                                <span class="text-gray-500">· {{ $candidate->type->value }} · seat {{ $candidate->seatAssignment?->seat->seat_no ?? '—' }}</span>
                                <span class="ml-1 rounded bg-white px-2 py-0.5 text-xs">{{ $candidate->status->label() }}</span>
                            </span>
                            @if ($candidate->status === \App\Enums\PassengerStatus::CheckedIn)
                                <form method="POST" action="{{ route('gate.flights.board', $flight) }}">
                                    @csrf
                                    <input type="hidden" name="pnr" value="{{ $choosingPnr }}">
                                    <input type="hidden" name="passenger_id" value="{{ $candidate->id }}">
                                    <button type="submit" class="rounded bg-navy px-3 py-1 text-xs font-medium text-white hover:bg-navy/90">Board</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @elseif (in_array($flight->status, [\App\Enums\FlightStatus::Closed, \App\Enums\FlightStatus::Departed], true))
        <p class="mt-2 text-sm text-gray-600">Boarding is closed for this flight.</p>
    @else
        <p class="mt-2 text-sm text-gray-600">Boarding has not started ({{ $flight->status->label() }}).</p>

        {{-- Start boarding: only from CheckinOpen, after an on-page confirmation (no JavaScript). --}}
        @if ($flight->status === \App\Enums\FlightStatus::CheckinOpen)
            @if ($confirmingStart)
                <div class="mt-3 rounded border border-navy/30 bg-sky-50 p-4">
                    <p class="font-medium text-navy">Start boarding for {{ $flight->flight_no }}?</p>
                    <p class="mt-1 text-sm text-gray-700">The flight goes from Check-in open to Boarding; check-in is then closed for it.</p>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('gate.flights.start-boarding', $flight) }}">
                            @csrf
                            <button type="submit" class="rounded bg-navy px-4 py-2 text-sm font-semibold text-white hover:bg-navy/90">
                                Yes, start boarding
                            </button>
                        </form>
                        <a href="{{ route('gate.flights.show', $flight) }}" class="text-sm font-medium text-gray-700 hover:underline">Cancel</a>
                    </div>
                </div>
            @else
                <a href="{{ route('gate.flights.show', [$flight, 'start' => 'confirm']) }}"
                   class="mt-3 inline-block rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">
                    Start boarding
                </a>
            @endif
        @endif
    @endif
</div>
