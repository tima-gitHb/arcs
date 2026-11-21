<x-app-layout>
    @php
        // The flight this screen is about (B13b: the first of the agent's direction, or ?flight=).
        $flight ??= $passenger->reservation->flight;
        $currentSeat = $passenger->seatAssignment?->seat;
        $picking = $passenger->status === \App\Enums\PassengerStatus::Booked
            && $passenger->type !== \App\Enums\PassengerType::Infant;
        $canChange = $passenger->status === \App\Enums\PassengerStatus::CheckedIn
            && $currentSeat
            && ! $flight->isBoardingClosed()
            && $flight->status !== \App\Enums\FlightStatus::Cancelled;
        $showSeatMap = $picking || ($canChange && $changingSeat);

        // Booked opens on Documents. Checked in (and later statuses) open on Done.
        // A validation error, an open edit, or Change seat opens that step instead.
        $initialStep = $passenger->status === \App\Enums\PassengerStatus::Booked ? 0 : 5;
        if ($errors->getBag('addDocument')->any() || $errors->getBag('editDocument')->any() || $editing) {
            $initialStep = 0;
        } elseif ($changingSeat || $errors->has('seat_id') || $errors->has('seat')) {
            $initialStep = 1;
        } elseif ($errors->getBag('addBag')->any()) {
            $initialStep = 2;
        } elseif ($errors->getBag('addService')->any()) {
            $initialStep = 3;
        } elseif ($errors->has('card_number') || $errors->has('card_expiry') || $errors->has('card_cvc') || $errors->has('card_name') || $errors->has('entry_reason')) {
            $initialStep = 4;
        }

        $checkinSteps = ['Documents', 'Seat', 'Bags & equipment', 'Services', 'Payment', 'Done'];
        $cabinLabel = ($currentSeat?->cabin_class ?? $passenger->cabin_class)?->label();
        $initials = strtoupper(mb_substr((string) $passenger->first_name, 0, 1).mb_substr((string) $passenger->last_name, 0, 1));
        $legs = $journeyFlights ?? collect();
        $routeCodes = $legs->isNotEmpty()
            ? collect([$legs->first()->origin])->merge($legs->pluck('destination'))->implode(' → ')
            : $flight->origin.' → '.$flight->destination;
        $finalDestination = $legs->isNotEmpty() ? $legs->last()->destination : $flight->destination;
        $bagCount = $passenger->bags->count();
        $connectionLabel = '—';
        if ($legs->count() > 1) {
            foreach ($legs as $index => $leg) {
                if ($index < $legs->count() - 1 && $leg->arrives_at) {
                    $next = $legs[$index + 1];
                    $gapMinutes = (int) abs($leg->arrives_at->diffInMinutes($next->departure_at, false));
                    $hours = intdiv($gapMinutes, 60);
                    $mins = $gapMinutes % 60;
                    $connectionLabel = $hours > 0 ? $hours.' h '.str_pad((string) $mins, 2, '0', STR_PAD_LEFT) : $mins.' min';
                    break;
                }
            }
        }
    @endphp

    <x-slot name="aside">
        <div class="mb-4 grid grid-cols-3 gap-2 rounded-[14px] border bg-white px-2 py-3 text-center" style="border-color: var(--arcs-border);">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Seat</p>
                <p class="mt-1 text-sm font-semibold">{{ $currentSeat?->seat_no ?? '—' }}</p>
            </div>
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Bags</p>
                <p class="mt-1 text-sm font-semibold">{{ $bagCount }} · {{ $finalDestination }}</p>
            </div>
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Connection</p>
                <p class="mt-1 text-sm font-semibold">{{ $connectionLabel }}</p>
            </div>
        </div>

        @if ($showSeatMap)
            <p class="mb-2 flex items-baseline justify-between gap-2 text-sm">
                <span class="font-semibold">{{ $flight->flight_no }} · seat map</span>
                <span class="text-gray-500">Gate {{ $flight->gate ?? '—' }}</span>
            </p>
            <x-seat-map-dock
                :hint="$picking ? 'Click a free seat to assign it. Taken seats stay blocked.' : 'Click a free seat in the same cabin. A new boarding pass replaces the old one.'"
                data-seats="{{ $seatMap->toJson() }}"
                data-selected-seat="{{ $currentSeat?->id }}"
                data-cabin="{{ $picking ? '' : $currentSeat->cabin_class->value }}"
            />
        @endif
    </x-slot>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 items-start gap-3">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-sm font-semibold" style="background: var(--arcs-accent-soft); color: var(--arcs-accent-text);" aria-hidden="true">
                {{ $initials }}
            </div>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-[22px] font-bold tracking-tight text-gray-900 sm:text-2xl">{{ $passenger->first_name }} {{ $passenger->last_name }}</h1>
                    <x-status-badge :status="$passenger->status" />
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $passenger->type->value }}
                    @if ($cabinLabel)
                        · {{ $cabinLabel }}
                    @endif
                    · {{ $routeCodes }}
                    · <span class="arcs-mono tracking-wider">{{ $passenger->reservation->pnr }}</span>
                </p>
                {{-- Exact "ME204 · CDG → BEY" form kept for SR-66 Paris return screen (B13bSegmentsTest). --}}
                <p class="mt-1 text-sm font-medium text-gray-800">
                    {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}
                    @if ($passenger->accompanyingAdult)
                        (with {{ $passenger->accompanyingAdult->first_name }} {{ $passenger->accompanyingAdult->last_name }})
                    @endif
                </p>
                <p class="mt-1 text-sm text-gray-500">
                    Departure <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M H:i" />
                    · Gate {{ $flight->gate ?? '—' }}
                    @if ($passenger->checkedInBy)
                        · Checked in by {{ $passenger->checkedInBy->user->name }}, <x-station-time :at="$passenger->checkedInBy->changed_at" format="H:i" />
                    @endif
                </p>
            </div>
        </div>
        <a href="{{ route('checkin.home', ['q' => $passenger->reservation->pnr]) }}" class="arcs-btn-secondary">← Back to search</a>
    </div>

    @if (isset($journeyFlights) && $journeyFlights->count() > 1)
        {{-- Through check-in (C14 / SR-66): whole direction, local times, connection gap. --}}
        <x-panel class="mb-6" data-journey="1">
            <p class="text-sm text-gray-500">
                Connection to {{ $journeyFlights->last()->destination }}: check-in covers every flight, bags go to {{ $journeyFlights->last()->destination }}.
            </p>
            <ul class="mt-2 space-y-2 text-sm">
                @foreach ($journeyFlights as $index => $leg)
                    <li @class(['font-medium' => $leg->is($flight)]) @style(['color: var(--arcs-accent-text)' => $leg->is($flight)])>
                        {{ $leg->flight_no }} · {{ $leg->origin }} → {{ $leg->destination }}
                        · dep <x-local-time :at="$leg->departure_at" :airport="$leg->origin" format="d M H:i" />
                        @if ($leg->arrives_at)
                            · arr <x-local-time :at="$leg->arrives_at" :airport="$leg->destination" format="d M H:i" />
                        @endif
                        · Seat {{ $seatsByFlight->get($leg->id)?->seat?->seat_no ?? 'none' }}
                        @unless ($leg->is($flight))
                            <a href="{{ route('checkin.passengers.show', [$passenger, 'flight' => $leg->id]) }}" class="ml-1 font-medium hover:underline" style="color: var(--arcs-accent-text);">Seat on {{ $leg->flight_no }}</a>
                        @endunless
                    </li>
                    @if ($index < $journeyFlights->count() - 1 && $leg->arrives_at)
                        @php
                            $next = $journeyFlights[$index + 1];
                            $gap = (int) $leg->arrives_at->diffInMinutes($next->departure_at, false);
                        @endphp
                        <li class="pl-4 text-xs text-gray-500">Connection at {{ $leg->destination }}: {{ $gap }} min</li>
                    @endif
                @endforeach
            </ul>
        </x-panel>
    @endif

    <div x-data="{ step: {{ (int) $initialStep }} }" x-cloak>
        <x-step-bar class="mb-6" navigate :steps="$checkinSteps" :current="$initialStep" />

        <div class="space-y-6">
            <div x-show="step === 0" class="space-y-6">
                <x-panel>
                    <h2 class="text-base font-semibold text-gray-900">Travel documents</h2>
                    @php($editable = $passenger->documentsEditable())

                    @if ($passenger->travelDocuments->isEmpty())
                        <p class="mt-2 text-sm text-gray-600">No travel documents.</p>
                    @else
                        <table class="mt-4 w-full border-separate border-spacing-y-3">
                            <tbody>
                            @foreach ($passenger->travelDocuments as $document)
                                <tr>
                                    <td class="rounded-[14px] border px-4 py-3" style="border-color: var(--arcs-border);">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="min-w-0 text-sm">
                                        <p class="font-medium text-gray-900">{{ $document->type->label() }} · {{ $document->issuingCountryName() }}</p>
                                        {{-- SR-34: full number only for check-in staff and the Manager. Order matches the list test: type, number, country, expiry. --}}
                                        <p class="mt-0.5 arcs-mono text-xs text-gray-600">{{ $document->maskedNumberFor(auth()->user()) }}</p>
                                        <p class="text-gray-600">{{ $document->issuingCountryName() }}</p>
                                        <p class="mt-0.5 text-gray-500">Expires {{ $document->expiry_date->format('d M Y') }}</p>
                                        @if ($document->verified_at)
                                            <p class="mt-1 text-xs text-gray-500">{{ $document->verifier?->name }}, <x-station-time :at="$document->verified_at" format="d M H:i" /></p>
                                        @elseif (! $editable)
                                            <p class="mt-1 text-xs text-gray-500">Not verified</p>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2">
                                        @if ($editable)
                                            <a href="{{ route('checkin.passengers.show', [$passenger, 'edit' => $document->id]) }}" class="text-xs font-medium hover:underline" style="color: var(--arcs-accent-text);">
                                                Edit
                                            </a>
                                        @endif
                                        @if ($document->verified_at)
                                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold" style="background: var(--arcs-success-bg); color: var(--arcs-success);">Verified</span>
                                        @elseif ($editable)
                                            <form method="POST" action="{{ route('checkin.documents.verify', $document) }}">
                                                @csrf
                                                <button type="submit" class="arcs-btn-primary">Verify</button>
                                            </form>
                                        @endif
                                    </div>
                                    </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif

                    @include('checkin.partials.entry-card')

                    @if (! $editable)
                        <p class="mt-4 text-sm text-gray-600">
                            Documents can no longer be changed: this passenger is {{ strtolower($passenger->status->label()) }}.
                        </p>
                    @endif

                    @if ($editable && $editing)
                        <div class="mt-6 border-t pt-4" style="border-color: var(--arcs-border);">
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
                        <div class="mt-6 border-t pt-4" style="border-color: var(--arcs-border);">
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
                </x-panel>
            </div>

            <div x-show="step === 1" class="space-y-6">
                @include('checkin.partials.seat-card')

                @if (in_array($passenger->status, [\App\Enums\PassengerStatus::Booked, \App\Enums\PassengerStatus::CheckedIn], true)
                    && $passenger->type !== \App\Enums\PassengerType::Infant
                    && ! $flight->isBoardingClosed())
                    <x-panel>
                        <a href="{{ route('checkin.passengers.upgrade', [$passenger, 'flight' => $flight->id]) }}" class="arcs-btn-secondary">
                            Upgrade
                        </a>
                        <p class="mt-2 text-xs text-gray-500">Paid cabin upgrade to a higher class{{ ($journeyFlights ?? collect())->count() > 1 ? ' on '.$flight->flight_no : '' }}.</p>
                    </x-panel>
                @endif
            </div>

            <div x-show="step === 2 || step === 4">
                @include('checkin.partials.bags-card')
            </div>

            <div x-show="step === 3">
                @include('checkin.partials.services-card')
            </div>

            <div x-show="step === 4" class="space-y-6">
                @if ($passenger->status === \App\Enums\PassengerStatus::Booked)
                    {{-- Completes check-in: StatusService::checkIn(), then the boarding pass (C5). --}}
                    <form method="POST" action="{{ route('checkin.passengers.confirm', $passenger) }}">
                        @csrf
                        @include('checkin.partials.entry-reason')
                        <button type="submit" class="arcs-btn-primary">
                            Confirm check-in
                        </button>
                    </form>
                @endif
            </div>

            <div x-show="step === 5" class="space-y-6">
                @include('checkin.partials.boarding-pass-card')
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if ($flight->checkin_closes_at)
                    <p class="text-sm text-gray-500">
                        Check-in closes <x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="H:i" />
                    </p>
                @endif
                <button type="button" class="arcs-btn-secondary" x-show="step > 0" x-cloak x-on:click="step = step - 1">Back</button>
                <button type="button" class="arcs-btn-primary ml-auto" x-show="step < 5" x-cloak x-on:click="step = step + 1">
                    <span x-show="step === 0">Continue to seat</span>
                    <span x-show="step === 1">Continue to bags</span>
                    <span x-show="step === 2">Continue to services</span>
                    <span x-show="step === 3">Continue to payment</span>
                    <span x-show="step === 4">Continue</span>
                </button>
            </div>
        </div>
    </div>

    @if ($showSeatMap)
        <script type="module">
            const map = document.getElementById('seat-map');
            const seats = JSON.parse(map.dataset.seats);
            const selected = map.dataset.selectedSeat;
            const cabin = map.dataset.cabin;
            const form = document.getElementById('seat-form');

            window.ArcsSeatMap.draw(map, seats, (seat) => {
                if (String(seat.id) === selected) {
                    return { style: 'mine', disabled: true };
                }

                return {
                    style: window.ArcsSeatMap.cabinStyle(seat),
                    disabled: seat.taken || (cabin !== '' && seat.cabin !== cabin),
                    onPick: (picked) => {
                        document.getElementById('seat-input').value = picked.id;
                        form.submit();
                    },
                };
            });
        </script>
    @endif
</x-app-layout>
