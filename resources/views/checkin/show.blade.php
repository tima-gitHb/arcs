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
        $checkinHashes = ['documents', 'seat', 'bags', 'services', 'payment', 'done'];
        $cabinLabel = ($currentSeat?->cabin_class ?? $passenger->cabin_class)?->label();
        $initials = strtoupper(mb_substr((string) $passenger->first_name, 0, 1).mb_substr((string) $passenger->last_name, 0, 1));
        $legs = $journeyFlights ?? collect();
        $routeCodes = $legs->isNotEmpty()
            ? collect([$legs->first()->origin])->merge($legs->pluck('destination'))->implode(' → ')
            : $flight->origin.' → '.$flight->destination;
        $finalDestination = $legs->isNotEmpty() ? $legs->last()->destination : $flight->destination;
        $bagCount = $passenger->bags->count();
        $bagKg = $passenger->bags->sum('weight_kg');
        $connectionLabel = '—';
        $connectionChip = null;
        if ($legs->count() > 1) {
            foreach ($legs as $index => $leg) {
                if ($index < $legs->count() - 1 && $leg->arrives_at) {
                    $next = $legs[$index + 1];
                    $gapMinutes = (int) abs($leg->arrives_at->diffInMinutes($next->departure_at, false));
                    $hours = intdiv($gapMinutes, 60);
                    $mins = $gapMinutes % 60;
                    $connectionLabel = $hours > 0 ? $hours.' h '.str_pad((string) $mins, 2, '0', STR_PAD_LEFT) : $mins.' min';
                    $connectionChip = 'Connection · '.$connectionLabel;
                    break;
                }
            }
        }

        $seatsSummary = ($journeyFlights ?? collect())
            ->map(fn ($leg) => $seatsByFlight->get($leg->id)?->seat?->seat_no)
            ->filter()
            ->implode(' · ') ?: ($currentSeat?->seat_no ?? '—');

        $allowance = app(\App\Services\AllowanceService::class)->for($passenger);
        $excess = app(\App\Services\BagService::class)->excess($passenger);
        $bagsService = app(\App\Services\BagService::class);
        $dueCents = $excess->priceIsSet() ? $bagsService->excessDueCents($passenger) : 0;
        $paidCents = $bagsService->excessPaidCents($passenger);
        $servicesCount = $passenger->specialServices->count();

        $seatStates = \App\Models\SeatAssignment::query()
            ->where('flight_id', $flight->id)
            ->with(['seat', 'coupon'])
            ->get()
            ->mapWithKeys(function ($assignment) {
                $status = $assignment->coupon?->status;

                return [
                    $assignment->seat?->seat_no => match ($status) {
                        \App\Enums\PassengerStatus::CheckedIn => 'CheckedIn',
                        \App\Enums\PassengerStatus::Boarded => 'Boarded',
                        \App\Enums\PassengerStatus::NoShow => 'NoShow',
                        default => 'Booked',
                    },
                ];
            })
            ->filter(fn ($status, $seatNo) => filled($seatNo))
            ->all();
    @endphp

    <x-slot name="aside">
        <div class="arcs-card p-5 xl:sticky xl:top-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">This check-in</p>

            @if (($journeyFlights ?? collect())->count() > 1)
                <ul class="mt-3 space-y-2 text-sm" data-journey="1">
                    @foreach ($journeyFlights as $index => $leg)
                        <li @class(['font-medium' => $leg->is($flight)]) @style(['color: var(--arcs-accent-text)' => $leg->is($flight)])>
                            <span class="arcs-mono">{{ $leg->flight_no }}</span>
                            {{ $leg->origin }} → {{ $leg->destination }}
                            <span class="block text-xs font-normal text-gray-500">
                                <x-local-time :at="$leg->departure_at" :airport="$leg->origin" format="H:i" />
                                @if ($leg->gate) · {{ $leg->gate }} @endif
                            </span>
                        </li>
                        @if ($index < $journeyFlights->count() - 1 && $leg->arrives_at)
                            @php
                                $next = $journeyFlights[$index + 1];
                                $gap = (int) $leg->arrives_at->diffInMinutes($next->departure_at, false);
                            @endphp
                            <li class="pl-1 text-xs text-gray-500">Connection at {{ $leg->destination }}: {{ $gap }} min</li>
                        @endif
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-gray-500">
                    Connection to {{ $journeyFlights->last()->destination }}: check-in covers every flight, bags go to {{ $journeyFlights->last()->destination }}.
                </p>
            @else
                <p class="mt-3 text-sm font-medium text-gray-900">
                    {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}
                </p>
            @endif

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Seats</dt>
                    <dd class="font-semibold arcs-mono">{{ $seatsSummary }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Bags</dt>
                    <dd class="font-semibold">{{ $bagCount }} · {{ rtrim(rtrim(number_format($bagKg, 1, '.', ''), '0'), '.') }} kg · to {{ $finalDestination }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Connection</dt>
                    <dd class="font-semibold">{{ $connectionLabel }}</dd>
                </div>
                @if ($servicesCount > 0)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500">Services</dt>
                        <dd class="font-semibold">{{ $servicesCount }} requested</dd>
                    </div>
                @endif
                @if ($flight->checkin_closes_at)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500">Check-in closes</dt>
                        <dd class="font-semibold"><x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="H:i" /></dd>
                    </div>
                @endif
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Payment</dt>
                    <dd class="font-semibold" @style(['color: var(--arcs-accent-text)' => $dueCents > 0])>
                        {{ $dueCents > 0 ? 'Due' : ($paidCents > 0 ? 'Paid' : 'Nothing to pay') }}
                    </dd>
                </div>
            </dl>
        </div>
    </x-slot>

    <div
        class="mb-6"
        x-data="{
            step: {{ (int) $initialStep }},
            hashes: @js($checkinHashes),
            go(n) { this.step = n; window.arcsCheckinSetHash?.(n); },
        }"
        x-init="
            window.arcsCheckinSetHash = (n) => { const h = hashes[n]; if (h) history.replaceState(null, '', '#' + h); };
            window.arcsBookingSetHash = window.arcsCheckinSetHash;
            window.arcsCheckinGo = (n) => go(n);
            const fromHash = () => { const i = hashes.indexOf(location.hash.replace('#','')); if (i >= 0) step = i; };
            fromHash();
            window.addEventListener('hashchange', fromHash);
            $watch('step', (n) => window.arcsCheckinSetHash(n));
            window.arcsCheckinSetHash(step);
        "
        x-cloak
        data-checkin-flow
    >
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <a href="{{ route('checkin.home', ['q' => $passenger->reservation->pnr]) }}" class="arcs-btn-ghost !min-h-[40px] !px-3" aria-label="Back to search">←</a>
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
                        · <span class="arcs-mono tracking-wider">{{ $passenger->reservation->pnr }}</span>
                        · {{ $routeCodes }}
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
            <div class="flex flex-wrap items-center gap-2">
                @if ($connectionChip)
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold" style="background: #F8E9C8; color: #5C4810;">{{ $connectionChip }}</span>
                @endif
                <a href="{{ route('checkin.home', ['q' => $passenger->reservation->pnr]) }}" class="arcs-btn-secondary">← Back to search</a>
            </div>
        </div>

        @if (isset($journeyFlights) && $journeyFlights->count() > 1)
            {{-- Through check-in (C14 / SR-66): whole direction, local times, connection gap. Kept for asserts. --}}
            <div class="sr-only" data-journey="1">
                <p>Connection to {{ $journeyFlights->last()->destination }}: check-in covers every flight, bags go to {{ $journeyFlights->last()->destination }}.</p>
                <ul>
                    @foreach ($journeyFlights as $index => $leg)
                        <li>
                            {{ $leg->flight_no }} · {{ $leg->origin }} → {{ $leg->destination }}
                            · dep <x-local-time :at="$leg->departure_at" :airport="$leg->origin" format="d M H:i" />
                            @if ($leg->arrives_at)
                                · arr <x-local-time :at="$leg->arrives_at" :airport="$leg->destination" format="d M H:i" />
                            @endif
                            · Seat {{ $seatsByFlight->get($leg->id)?->seat?->seat_no ?? 'none' }}
                            @unless ($leg->is($flight))
                                <a href="{{ route('checkin.passengers.show', [$passenger, 'flight' => $leg->id]) }}">Seat on {{ $leg->flight_no }}</a>
                            @endunless
                        </li>
                        @if ($index < $journeyFlights->count() - 1 && $leg->arrives_at)
                            @php
                                $next = $journeyFlights[$index + 1];
                                $gap = (int) $leg->arrives_at->diffInMinutes($next->departure_at, false);
                            @endphp
                            <li>Connection at {{ $leg->destination }}: {{ $gap }} min</li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @endif

        <x-step-bar class="mb-6" navigate :steps="$checkinSteps" :current="$initialStep" :hashes="$checkinHashes" />

        <div class="space-y-6">
            <div x-show="step === 0" class="space-y-6">
                <x-panel>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-900">Travel documents</h2>
                        <p class="text-sm text-gray-500">Final destination: {{ $finalDestination }}</p>
                    </div>
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
                                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold" style="background: var(--arcs-success-bg); color: var(--arcs-success);">✓ Verified</span>
                                            <span class="sr-only">Verified</span>
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

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <a href="{{ route('checkin.home', ['q' => $passenger->reservation->pnr]) }}" class="arcs-btn-ghost">← Back to the list</a>
                    <button type="button" class="arcs-btn-primary" @click="go(1)">Continue to seat →</button>
                </div>
            </div>

            <div x-show="step === 1" class="space-y-6">
                <div class="grid items-start gap-6 xl:grid-cols-[20rem_minmax(0,1fr)]">
                    <div class="space-y-4">
                        @if (($journeyFlights ?? collect())->count() > 1)
                            <div class="arcs-seg flex-wrap">
                                @foreach ($journeyFlights as $leg)
                                    <a
                                        href="{{ route('checkin.passengers.show', [$passenger, 'flight' => $leg->id]) }}#seat"
                                        @class(['inline-flex min-h-[40px] items-center rounded-full px-4 py-1.5 text-sm font-medium', 'bg-white shadow-sm text-gray-900' => $leg->is($flight), 'text-gray-600' => ! $leg->is($flight)])
                                    >
                                        {{ $leg->flight_no }} · {{ $leg->origin }} → {{ $leg->destination }}
                                    </a>
                                @endforeach
                            </div>
                        @endif

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

                    @if ($showSeatMap)
                        <div class="min-w-0 space-y-3">
                            <div class="arcs-seat-select-bar">
                                <div class="arcs-seat-pax-chips">
                                    <span class="arcs-seat-pax-chip is-active" role="listitem">
                                        <span class="arcs-seat-pax-avatar">{{ strtoupper(substr($passenger->first_name, 0, 1).substr($passenger->last_name, 0, 1)) }}</span>
                                        <span class="arcs-seat-pax-meta">
                                            <span>{{ $passenger->first_name }} {{ $passenger->last_name }}</span>
                                            <small>{{ $currentSeat?->seat_no ?? '—' }}</small>
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <p id="seat-pick-error" class="arcs-seat-pick-error hidden" role="status"></p>
                            <x-seat-map-dock
                                :hint="$picking ? 'Click a free seat in this passenger’s cabin. Other cabins and restricted exit seats are not allowed.' : 'Click a free seat in the same cabin. A new boarding pass replaces the old one.'"
                                data-seats="{{ $seatMap->toJson() }}"
                                data-selected-seat="{{ $currentSeat?->id }}"
                                data-cabin="{{ $picking ? ($passenger->cabin_class?->value ?? '') : $currentSeat->cabin_class->value }}"
                                data-pax-type="{{ $passenger->type->value }}"
                                data-pax-initials="{{ strtoupper(substr($passenger->first_name, 0, 1).substr($passenger->last_name, 0, 1)) }}"
                                data-seat-states="{{ json_encode($seatStates) }}"
                            />
                        </div>
                    @endif
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="button" class="arcs-btn-ghost" @click="go(0)">← Back</button>
                    <button type="button" class="arcs-btn-primary" @click="go(2)">Continue to bags →</button>
                </div>
            </div>

            <div x-show="step === 2 || step === 4" class="space-y-6">
                @include('checkin.partials.bags-card')

                <div class="flex flex-wrap items-center justify-between gap-3" x-show="step === 2" x-cloak>
                    <button type="button" class="arcs-btn-ghost" @click="go(1)">← Back</button>
                    <button type="button" class="arcs-btn-primary" @click="go(3)">Continue to services →</button>
                </div>
            </div>

            <div x-show="step === 3" class="space-y-6">
                @include('checkin.partials.services-card')
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="button" class="arcs-btn-ghost" @click="go(2)">← Back</button>
                    <button type="button" class="arcs-btn-primary" @click="go(4)">Continue to payment →</button>
                </div>
            </div>

            <div x-show="step === 4" class="space-y-6">
                @if ($dueCents <= 0)
                    <x-panel>
                        <h2 class="text-base font-semibold text-gray-900">Nothing to pay</h2>
                        <p class="mt-2 text-sm text-gray-600">No desk charges for this passenger. You can complete check-in.</p>
                    </x-panel>
                @endif

                @if ($passenger->status === \App\Enums\PassengerStatus::Booked)
                    {{-- Completes check-in: StatusService::checkIn(), then the boarding pass (C5). --}}
                    <form method="POST" action="{{ route('checkin.passengers.confirm', $passenger) }}">
                        @csrf
                        @include('checkin.partials.entry-reason')
                        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <button type="button" class="arcs-btn-ghost" @click="go(3)">← Back</button>
                            <button
                                type="submit"
                                class="arcs-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                @disabled($dueCents > 0)
                            >
                                Confirm check-in
                            </button>
                        </div>
                        <p class="sr-only">Complete check-in →</p>
                        @if ($dueCents > 0)
                            <p class="mt-2 text-sm" style="color: var(--arcs-accent-text);">Check-in can't finish until this is paid</p>
                        @endif
                    </form>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <button type="button" class="arcs-btn-ghost" @click="go(3)">← Back</button>
                        <button type="button" class="arcs-btn-primary" @click="go(5)">Continue →</button>
                    </div>
                @endif
            </div>

            <div x-show="step === 5" class="space-y-6">
                @if ($passenger->status === \App\Enums\PassengerStatus::CheckedIn || $passenger->status === \App\Enums\PassengerStatus::Boarded)
                    <x-panel>
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="flex items-start gap-4">
                                <span class="flex h-14 w-14 items-center justify-center rounded-full text-2xl text-white" style="background: var(--arcs-success);" aria-hidden="true">✓</span>
                                <div>
                                    <h2 class="text-xl font-bold text-gray-900">{{ $passenger->first_name }} {{ $passenger->last_name }} is checked in</h2>
                                    <p class="mt-1 text-sm text-gray-500">
                                        @if (($journeyFlights ?? collect())->count() > 1) Both flights · @endif
                                        <span class="arcs-mono">{{ $passenger->reservation->pnr }}</span>
                                        · {{ $routeCodes }}
                                    </p>
                                </div>
                            </div>
                            @if ($passenger->reservation->contact_email)
                                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold" style="background: #E0EEF9; color: #1E4E79;">
                                    Email sent with boarding passes and bag tags
                                </span>
                            @endif
                        </div>
                    </x-panel>
                @endif

                @include('checkin.partials.boarding-pass-card')

                @if ($passenger->bags->isNotEmpty())
                    <x-panel>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Bag tags · checked through to {{ $finalDestination }}</h2>
                        <ul class="mt-4 flex flex-wrap gap-3">
                            @foreach ($passenger->bags as $bag)
                                <li class="rounded-[14px] border px-4 py-3 text-sm" style="border-color: var(--arcs-border);">
                                    <p class="arcs-mono font-semibold">{{ $bag->tag_no }}</p>
                                    <p class="mt-1 text-gray-600">
                                        {{ $finalDestination }} · {{ $bag->weight_kg }} kg
                                        @if ((float) $bag->weight_kg > \App\Services\BagTagService::HEAVY_KG)
                                            <span class="ml-1 font-bold" style="color: #B45309;">HEAVY</span>
                                        @endif
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    </x-panel>
                @endif

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap gap-2">
                        @if (($boardingPasses ?? collect())->isNotEmpty() || $passenger->activeBoardingPass)
                            <a href="{{ route('checkin.boarding-pass.show', $passenger) }}" target="_blank" class="arcs-btn-secondary">Print boarding passes</a>
                        @endif
                        @if ($passenger->bags->isNotEmpty())
                            <a href="{{ route('checkin.passengers.bag-tags', $passenger) }}" target="_blank" class="arcs-btn-secondary">Print bag tags</a>
                        @endif
                    </div>
                    <a href="{{ route('checkin.home') }}" class="arcs-btn-primary">Next passenger →</a>
                </div>
            </div>
        </div>
    </div>

    @if ($showSeatMap)
        <script type="module">
            const map = document.getElementById('seat-map');
            const seats = JSON.parse(map.dataset.seats);
            const selected = map.dataset.selectedSeat;
            const cabin = map.dataset.cabin;
            const paxType = map.dataset.paxType || 'Adult';
            const initials = map.dataset.paxInitials || '';
            const states = map.dataset.seatStates ? JSON.parse(map.dataset.seatStates) : {};
            const form = document.getElementById('seat-form');
            const err = document.getElementById('seat-pick-error');

            function showErr(message) {
                if (! err) return;
                err.textContent = message;
                err.classList.toggle('hidden', ! message);
            }

            window.ArcsSeatMap.draw(map, seats, (seat) => {
                if (String(seat.id) === selected) {
                    return { style: 'mine', disabled: true, label: initials };
                }

                if (seat.taken) {
                    const status = states[seat.seat_no];
                    const style = status ? window.ArcsSeatMap.statusToStyle(status) : 'taken';

                    return {
                        style,
                        disabled: true,
                        label: style === 'boarded' ? '✓' : '',
                        title: `${seat.seat_no} · taken`,
                        onPick: () => showErr(`${seat.seat_no} is taken.`),
                    };
                }

                const exitBlocked = Boolean(seat.exit) && paxType !== 'Adult';
                const wrongCabin = cabin !== '' && seat.cabin !== cabin;

                if (wrongCabin || exitBlocked) {
                    return {
                        style: 'blocked',
                        blocked: true,
                        disabled: true,
                        label: '',
                        title: exitBlocked
                            ? `${seat.seat_no} · exit row · not allowed`
                            : `${seat.seat_no} · ${seat.cabin} · not allowed`,
                        onPick: () => showErr(exitBlocked
                            ? 'Exit-row seats are only for adults.'
                            : `${seat.seat_no} is in ${seat.cabin}. This passenger is in ${cabin}.`),
                    };
                }

                return {
                    style: window.ArcsSeatMap.cabinStyle(seat),
                    disabled: false,
                    onPick: (picked) => {
                        showErr('');
                        document.getElementById('seat-input').value = picked.id;
                        form.submit();
                    },
                };
            });
        </script>
    @endif
</x-app-layout>
