{{-- Boarding panel (G2 / G6, SR-10 / SR-62): start boarding, scan result, and the shared-PNR chooser.
     The scan box itself sits in the page header. Expects $flight, $choosing, $choosingPnr, $confirmingStart. --}}
@if ($flight->status === \App\Enums\FlightStatus::Boarding)
    @if (session('board_result') || ($choosing && $choosing->count() > 1))
        <x-panel>
            @if (session('board_result') === 'ok')
                <div class="boarded-ok rounded-[14px] border-2 px-6 py-8 text-center" style="border-color: var(--arcs-boarded); background: var(--arcs-success-bg);" role="status">
                    <p class="text-6xl font-bold" style="color: var(--arcs-boarded);" aria-hidden="true">✓</p>
                    <p class="mt-3 text-2xl font-semibold" style="color: var(--arcs-success);">{{ session('boarded_name') }}</p>
                    @if (session('boarded_seat'))
                        <p class="mt-1 font-mono text-xl" style="color: var(--arcs-boarded);">Seat {{ session('boarded_seat') }}</p>
                    @else
                        <p class="mt-1 text-lg" style="color: var(--arcs-boarded);">Infant · no seat</p>
                    @endif
                </div>
            @elseif (session('board_result') === 'fail')
                <div class="boarded-fail rounded-[14px] border-2 bg-red-50 px-6 py-8 text-center" style="border-color: var(--arcs-error);" role="alert">
                    <p class="text-5xl font-bold" style="color: var(--arcs-error);" aria-hidden="true">✗</p>
                    <p class="mt-3 text-xl font-semibold" style="color: var(--arcs-error);">{{ session('error') }}</p>
                </div>
            @endif

            @if ($choosing && $choosing->count() > 1)
                <div @class(['rounded-lg border p-4' => true, 'mt-4' => session('board_result')]) style="border-color: var(--arcs-accent); background: var(--arcs-accent-soft);">
                    <p class="text-sm font-medium" style="color: var(--arcs-accent-text);">Choose the passenger for PNR {{ $choosingPnr }}</p>
                    <ul class="mt-2 divide-y" style="border-color: var(--arcs-border);">
                        @foreach ($choosing as $candidate)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                                <span>
                                    {{ $candidate->first_name }} {{ $candidate->last_name }}
                                    <span class="text-gray-500">· {{ $candidate->type->value }} · seat {{ $candidate->seatAssignment?->seat->seat_no ?? '—' }}</span>
                                    <x-status-badge class="ml-1" :status="$candidate->status" />
                                </span>
                                @if ($candidate->status === \App\Enums\PassengerStatus::CheckedIn)
                                    <form method="POST" action="{{ route('gate.flights.board', $flight) }}">
                                        @csrf
                                        <input type="hidden" name="pnr" value="{{ $choosingPnr }}">
                                        <input type="hidden" name="passenger_id" value="{{ $candidate->id }}">
                                        <x-primary-button class="!min-h-0 px-3 py-1 text-xs">Board</x-primary-button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-panel>
    @endif
@elseif (in_array($flight->status, [\App\Enums\FlightStatus::Closed, \App\Enums\FlightStatus::Departed], true))
    <x-panel>
        <p class="text-sm text-gray-600">Boarding is closed for this flight.</p>
    </x-panel>
@else
    <x-panel>
        <p class="text-sm text-gray-600">Boarding has not started ({{ $flight->status->label() }}).</p>

        {{-- Start boarding: only from CheckinOpen, after an on-page confirmation (no JavaScript). --}}
        @if ($flight->status === \App\Enums\FlightStatus::CheckinOpen)
            @if ($confirmingStart)
                <div class="mt-3 rounded-lg border p-4" style="border-color: var(--arcs-accent); background: var(--arcs-accent-soft);">
                    <p class="font-medium" style="color: var(--arcs-accent-text);">Start boarding for {{ $flight->flight_no }}?</p>
                    <p class="mt-1 text-sm text-gray-700">The flight goes from Check-in open to Boarding; check-in is then closed for it.</p>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('gate.flights.start-boarding', $flight) }}">
                            @csrf
                            <x-primary-button>Yes, start boarding</x-primary-button>
                        </form>
                        <a href="{{ route('gate.flights.show', $flight) }}" class="arcs-btn-secondary">Cancel</a>
                    </div>
                </div>
            @else
                <a href="{{ route('gate.flights.show', [$flight, 'start' => 'confirm']) }}" class="arcs-btn-primary mt-3">
                    Start boarding
                </a>
            @endif
        @endif
    </x-panel>
@endif
