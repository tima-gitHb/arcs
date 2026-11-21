@php
    // Booked is not in $counts. Count it from the passengers already loaded for this page.
    $bookedOnPage = $passengers->filter(
        fn ($passenger) => $passenger->status === \App\Enums\PassengerStatus::Booked
    )->count();
@endphp

<x-app-layout>
    @if ($flight->status === \App\Enums\FlightStatus::Boarding)
        <x-slot name="search">
            <form method="POST" action="{{ route('gate.flights.board', $flight) }}" id="board-scan-form" class="flex max-w-md items-center gap-2">
                @csrf
                <x-input-label for="pnr" value="PNR on the boarding pass" class="sr-only" />
                <x-text-input id="pnr" name="pnr" type="text" maxlength="40"
                    placeholder="Scan boarding pass"
                    class="w-full min-w-[10rem] font-mono uppercase tracking-[0.12em]"
                    autocomplete="off" autofocus required />
                <button type="submit" class="arcs-btn-primary !min-h-0 shrink-0 px-3 py-2">Board</button>
            </form>
        </x-slot>
    @endif

    <x-slot name="aside">
        <div class="md:sticky md:top-4">
            <div class="mb-2 flex items-center justify-between gap-2">
                <p class="text-sm font-semibold text-gray-900">Seat map · {{ $flight->flight_no }}</p>
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold" style="color: var(--arcs-boarded);">
                    <span class="inline-block h-2 w-2 rounded-full" style="background: var(--arcs-boarded);"></span>
                    live
                </span>
            </div>
            <x-seat-map-dock
                mode="gate"
                hint="Live cabin. Click a seat to highlight that passenger. Updates every 10 seconds."
                data-seats="{{ $seatMap->toJson() }}"
                data-seat-states="{{ json_encode($seatStates) }}"
            />
        </div>
    </x-slot>

    <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2">
        <h1 class="text-[22px] font-bold tracking-tight text-gray-900 sm:text-2xl">
            {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}
        </h1>
        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide" style="background: var(--arcs-accent-soft); color: var(--arcs-accent);" data-flight-status>
            {{ $flight->status->label() }} · Gate {{ $flight->gate ?? 'TBA' }}
        </span>
        <p class="text-sm text-gray-500">
            Departs <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" />
            @if ($flight->checkin_closes_at)
                · doors close <x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="H:i" />
            @endif
        </p>
    </div>

    <div class="space-y-4">
        {{-- Live counts (SR-11). checked_in / boarded / missing refresh every 10 s. Booked is from this page's list. --}}
        <dl id="manifest-header" data-counts-url="{{ route('gate.flights.counts', $flight) }}" class="grid grid-cols-4 gap-3">
            <div class="rounded-[14px] border bg-white px-4 py-3 shadow-sm" style="border-color: var(--arcs-border);">
                <dt class="text-sm text-gray-500">Booked</dt>
                <dd class="mt-1 text-3xl font-semibold text-gray-900">{{ $bookedOnPage }}</dd>
            </div>
            <div class="rounded-[14px] border bg-white px-4 py-3 shadow-sm" style="border-color: var(--arcs-border);">
                <dt class="text-sm text-gray-500">Checked in</dt>
                <dd class="mt-1 text-3xl font-semibold text-gray-900" data-count="checked_in">{{ $counts['checked_in'] }}</dd>
            </div>
            <div class="rounded-[14px] border bg-white px-4 py-3 shadow-sm" style="border-color: var(--arcs-border);">
                <dt class="text-sm text-gray-500">Boarded</dt>
                <dd class="mt-1 text-3xl font-semibold" style="color: var(--arcs-boarded);" data-count="boarded">{{ $counts['boarded'] }}</dd>
            </div>
            <div class="rounded-[14px] border bg-white px-4 py-3 shadow-sm" style="border-color: var(--arcs-border);">
                <dt class="text-sm text-gray-500">Not boarded</dt>
                <dd class="mt-1 text-3xl font-semibold" style="color: #5C4810;" data-count="missing">{{ $counts['missing'] }}</dd>
            </div>
        </dl>

        @if (session('boardingPassUrl'))
            <a href="{{ session('boardingPassUrl') }}" target="_blank" class="arcs-btn-primary">
                Print boarding pass
            </a>
        @endif

        @include('gate.partials.boarding-panel')

        <x-panel title="Manifest">
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Seat</th>
                            <th>Passenger</th>
                            <th>Onward</th>
                            <th>Status</th>
                            <th>Checked in / boarded</th>
                            <th>Services · at the door</th>
                        </tr>
                    </thead>
                    <tbody id="manifest-passengers">
                        @forelse ($passengers as $passenger)
                            <tr
                                data-passenger="{{ $passenger->id }}"
                                data-pnr="{{ $passenger->reservation->pnr }}"
                                data-can-board="{{ $passenger->status === \App\Enums\PassengerStatus::CheckedIn ? '1' : '0' }}"
                                @if ($passenger->seatAssignment) data-seat="{{ $passenger->seatAssignment->seat->seat_no }}" @endif
                            >
                                <td class="align-top font-mono font-medium">{{ $passenger->seatAssignment?->seat->seat_no ?? '—' }}</td>
                                <td class="align-top">
                                    <span class="font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</span>
                                    <span class="block text-xs text-gray-500">
                                        {{ $passenger->type->value }} · PNR {{ $passenger->reservation->pnr }}
                                        @if ($passenger->accompanyingAdult)
                                            · with {{ $passenger->accompanyingAdult->first_name }}
                                        @endif
                                    </span>
                                </td>
                                <td class="align-top text-sm text-gray-700">
                                    {{-- Onward from already-eager segments.flight (no extra queries). --}}
                                    @php
                                        $segments = $passenger->reservation->segments->sortBy('sequence')->values();
                                        $here = $segments->search(fn ($segment) => (int) $segment->flight_id === (int) $flight->id);
                                        $nextSegment = $here === false ? null : $segments->get($here + 1);
                                        $nextFlight = $nextSegment?->relationLoaded('flight') ? $nextSegment->flight : null;
                                    @endphp
                                    @if ($nextFlight && $nextSegment->direction === \App\Enums\SegmentDirection::Return)
                                        <span class="block">return {{ $nextFlight->flight_no }} → {{ $nextFlight->destination }}</span>
                                    @elseif ($nextFlight)
                                        <span class="block">{{ $nextFlight->flight_no }} → {{ $nextFlight->destination }}</span>
                                    @endif
                                    @if ($passenger->connection)
                                        <span class="mt-1 block text-xs" style="color: var(--arcs-accent-text);">
                                            Connecting from {{ $passenger->connection['flight_no'] }}
                                            @if ($passenger->connection['arrives_at'])
                                                · arrives
                                                <x-local-time :at="$passenger->connection['arrives_at']" :airport="$passenger->connection['airport']" format="H:i" />
                                            @endif
                                            @if ($passenger->connection['label'])
                                                · {{ $passenger->connection['label'] }}
                                            @endif
                                            @if ($passenger->connection['tight'])
                                                <span class="ml-1 font-semibold" style="color: var(--arcs-error);">Tight connection</span>
                                            @endif
                                        </span>
                                    @elseif (! $nextFlight)
                                        —
                                    @endif
                                </td>
                                <td class="align-top">
                                    <x-status-badge :status="$passenger->status" />
                                    @if ($passenger->status === \App\Enums\PassengerStatus::Booked && $flight->isLateCheckInOpen())
                                        <a href="{{ route('gate.passengers.late-checkin', $passenger) }}" class="mt-1 block text-xs font-semibold hover:underline" style="color: var(--arcs-accent-text);">Late check-in</a>
                                    @endif
                                </td>
                                <td class="align-top text-xs text-gray-700">
                                    @if ($passenger->checkedInBy)
                                        <span class="block">Checked in by {{ $passenger->checkedInBy->user->name }} at <x-station-time :at="$passenger->checkedInBy->changed_at" format="H:i" /></span>
                                    @endif
                                    @if ($passenger->boardedBy)
                                        <span class="block">Boarded by {{ $passenger->boardedBy->user->name }} at <x-station-time :at="$passenger->boardedBy->changed_at" format="H:i" /></span>
                                    @endif
                                </td>
                                <td class="align-top text-xs">
                                    @foreach ($passenger->specialServices as $service)
                                        <span class="mr-1 inline-flex rounded-full px-2 py-0.5 font-semibold" style="background: var(--arcs-accent-soft); color: var(--arcs-accent-text);" title="{{ $service->type->label() }}">{{ $service->type->value }}</span>
                                    @endforeach
                                    @foreach ($passenger->bags as $bag)
                                        <span class="mt-1 block text-gray-700">{{ $bag->type->label() }} · <span class="font-mono">{{ $bag->tag_no }}</span></span>
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-gray-600">No passengers on this flight.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>

        @include('gate.partials.close-boarding')

        @if ($flight->status === \App\Enums\FlightStatus::Boarding)
            <x-panel>
                <p class="text-sm text-gray-600">Closing marks everyone not boarded as no-show and cancels their later flights on the same booking.</p>
                <p class="mt-1 text-xs text-gray-500">Scan the boarding pass or type the PNR, then Enter. Or select a row and board that passenger.</p>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    @unless ($confirmingClose)
                        <form method="POST" action="{{ route('gate.flights.board', $flight) }}" id="board-selected-form" class="contents">
                            @csrf
                            <input type="hidden" name="pnr" id="board-selected-pnr" value="">
                            <input type="hidden" name="passenger_id" id="board-selected-id" value="">
                            <button type="submit" id="board-selected" class="arcs-btn-secondary disabled:cursor-not-allowed disabled:opacity-50" disabled>Board selected</button>
                        </form>
                        <a href="{{ route('gate.flights.show', [$flight, 'close' => 'confirm']) }}" class="arcs-btn-primary">
                            Close boarding
                        </a>
                    @endunless
                </div>
            </x-panel>
        @endif
    </div>

    <script type="module">
        // Refresh the counts and seat colours every 10 seconds without reloading (SR-11).
        const header = document.getElementById('manifest-header');
        const map = document.getElementById('seat-map');
        const seats = JSON.parse(map.dataset.seats);
        const initialStates = JSON.parse(map.dataset.seatStates || '{}');
        let selectedId = null;

        const scanBox = document.getElementById('pnr');
        if (scanBox) {
            scanBox.value = '';
            scanBox.focus();
        }

        function selectedRow() {
            return selectedId === null ? null : document.querySelector(`tr[data-passenger="${selectedId}"]`);
        }

        function showSelection() {
            document.querySelectorAll('tr[data-passenger]').forEach((row) => {
                row.classList.toggle('arcs-row-selected', selectedId !== null && row.dataset.passenger === selectedId);
            });

            const row = selectedRow();
            const seatNo = row?.dataset.seat || null;
            map.querySelectorAll('button[data-seat-no]').forEach((button) => {
                const on = seatNo !== null && button.dataset.seatNo === seatNo;
                button.classList.toggle('ring-2', on);
                button.classList.toggle('ring-white', on);
            });

            const boardButton = document.getElementById('board-selected');
            const boardPnr = document.getElementById('board-selected-pnr');
            const boardId = document.getElementById('board-selected-id');
            if (boardButton && boardPnr && boardId) {
                const canBoard = row !== null && row.dataset.canBoard === '1';
                boardButton.disabled = !canBoard;
                boardPnr.value = canBoard ? row.dataset.pnr : '';
                boardId.value = canBoard ? row.dataset.passenger : '';
            }

            row?.scrollIntoView({ block: 'nearest' });
        }

        function selectPassenger(id) {
            const next = id === null ? null : String(id);
            selectedId = selectedId === next ? null : next;
            showSelection();
        }

        window.ArcsSeatMap.draw(map, seats, (seat) => {
            const status = initialStates[seat.seat_no];
            const style = status
                ? window.ArcsSeatMap.statusToStyle(status)
                : window.ArcsSeatMap.cabinStyle({ ...seat, taken: false });

            return {
                style,
                disabled: false,
                onPick: (picked) => {
                    const row = document.querySelector(`tr[data-seat="${CSS.escape(picked.seat_no)}"]`);
                    selectPassenger(row ? row.dataset.passenger : null);
                },
            };
        });

        document.getElementById('manifest-passengers')?.addEventListener('click', (event) => {
            const row = event.target.closest('tr[data-passenger]');
            if (!row || event.target.closest('a, button, input')) {
                return;
            }
            selectPassenger(row.dataset.passenger);
        });

        async function refreshCounts() {
            try {
                const response = await fetch(header.dataset.countsUrl, { headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    return;
                }
                const counts = await response.json();
                ['checked_in', 'boarded', 'missing'].forEach((key) => {
                    header.querySelector(`[data-count="${key}"]`).textContent = counts[key];
                });
                if (counts.seats) {
                    window.ArcsSeatMap.applySeatStates(map, counts.seats);
                    showSelection();
                }
            } catch {
                // Network hiccup: try again at the next tick.
            }
        }

        setInterval(refreshCounts, 10000);
    </script>
</x-app-layout>
