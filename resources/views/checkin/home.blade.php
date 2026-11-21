<x-app-layout>
    @php
        $todays = new \App\View\Components\TodaysFlights('checkin');
        $checkingIn = $todays->flights;
        $flightIds = $checkingIn->modelKeys();
        $couponCounts = collect();
        if ($flightIds !== []) {
            $coupons = \App\Models\Coupon::query()
                ->whereIn('flight_id', $flightIds)
                ->where('status', '!=', \App\Enums\PassengerStatus::Cancelled)
                ->get(['flight_id', 'status']);
            $couponCounts = $coupons->groupBy('flight_id')->map(function ($rows) {
                $checked = $rows->filter(fn ($coupon) => in_array($coupon->status, [
                    \App\Enums\PassengerStatus::CheckedIn,
                    \App\Enums\PassengerStatus::Boarded,
                    \App\Enums\PassengerStatus::NoShow,
                ], true))->count();

                return (object) ['total' => $rows->count(), 'checked_in' => $checked];
            });
        }
    @endphp

    <x-page-header
        title="Check in a passenger"
        subtitle="Find the booking, or open a flight that is checking in now."
    />

    <div class="space-y-8">
        <x-panel class="p-5 sm:p-6">
            <form method="GET" action="{{ route('checkin.home') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <label for="q" class="sr-only">PNR or last name</label>
                <input
                    id="q"
                    name="q"
                    type="text"
                    value="{{ $search }}"
                    autocomplete="off"
                    autofocus
                    placeholder="PNR or last name, e.g. JBGNWJ"
                    class="arcs-input min-h-[50px] flex-1 rounded-[14px] text-base"
                />
                <button type="submit" class="arcs-btn-primary shrink-0 px-6">Find booking</button>
            </form>
        </x-panel>

        @if ($search !== '' && $reservations->isEmpty())
            <x-empty-state message='Reservation not found for “{{ $search }}”.' />
        @endif

        @foreach ($reservations as $reservation)
            @php
                $flight = $reservation->flight;
                $windowOpen = $flight->isCheckinWindowOpen();
            @endphp

            <x-panel>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b pb-3" style="border-color: var(--arcs-border);">
                    <div>
                        <span class="arcs-mono text-lg font-semibold tracking-[0.15em]" style="color: var(--arcs-accent-text);">{{ $reservation->pnr }}</span>
                        <span class="ml-2 text-sm text-gray-700">
                            {{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}
                            · <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M H:i" />
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-status-badge :status="$flight->status" />
                        <x-status-badge :label="$windowOpen ? 'Window open' : 'Window closed'" :status="$windowOpen ? 'CheckinOpen' : 'Closed'" />
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    (<x-local-time :at="$flight->checkin_opens_at" :airport="$flight->origin" format="H:i" />–<x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="H:i" />)
                </p>

                @if ($reservation->passengers->count() > 1)
                    <div class="flex justify-end border-b py-2" style="border-color: var(--arcs-border);">
                        <a href="{{ route('checkin.reservations.group', $reservation) }}" class="arcs-btn-primary text-sm">Check in all</a>
                    </div>
                @endif

                <ul class="divide-y" style="border-color: var(--arcs-border);">
                    @foreach ($reservation->passengers as $passenger)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" style="border-color: var(--arcs-border);">
                            <div>
                                <span class="font-medium">{{ $passenger->first_name }} {{ $passenger->last_name }}</span>
                                <span class="ml-1 text-gray-500">{{ $passenger->type->value }}</span>
                                <x-status-badge class="ml-2" :status="$passenger->status" />
                            </div>

                            @if ($passenger->status === \App\Enums\PassengerStatus::Booked)
                                <a href="{{ route('checkin.passengers.show', $passenger) }}" class="arcs-btn-primary text-sm">Check in</a>
                            @elseif ($passenger->status === \App\Enums\PassengerStatus::CheckedIn)
                                <a href="{{ route('checkin.passengers.show', $passenger) }}" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Open</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-panel>
        @endforeach

        <section id="checking-in">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Checking in now</h2>
                    <p class="mt-1 text-sm text-gray-500">Open a flight to work the list at the desk.</p>
                </div>
                <span class="sr-only">Check-in list</span>
            </div>

            @if ($checkingIn->isEmpty())
                <x-empty-state message="No flights checking in at this station today." />
            @else
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($checkingIn as $flight)
                        @php
                            $counts = $couponCounts->get($flight->id);
                            $total = (int) ($counts->total ?? 0);
                            $done = (int) ($counts->checked_in ?? 0);
                            $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
                        @endphp
                        <a href="{{ route('checkin.flights.show', $flight) }}" class="arcs-card block p-5 transition hover:-translate-y-0.5 hover:shadow-md">
                            <div class="flex items-start justify-between gap-2">
                                <p class="arcs-mono text-sm font-semibold text-gray-900">
                                    {{ $flight->flight_no }} · gate {{ $flight->gate ?? '—' }}
                                </p>
                                <x-status-badge :status="$flight->status" />
                            </div>
                            <p class="mt-3 text-2xl font-bold tracking-tight text-gray-900">
                                {{ $flight->origin }} → {{ $flight->destination }}
                            </p>
                            <div class="mt-3 flex items-end justify-between gap-3">
                                <div>
                                    <p class="text-3xl font-semibold text-gray-900">
                                        <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" />
                                    </p>
                                    @if ($flight->checkin_closes_at)
                                        <p class="mt-0.5 text-xs text-gray-500">
                                            closes <x-local-time :at="$flight->checkin_closes_at" :airport="$flight->origin" format="H:i" />
                                        </p>
                                    @endif
                                </div>
                                <p class="text-right text-xs text-gray-500">
                                    Checked in {{ $done }} of {{ $total }}
                                </p>
                            </div>
                            <div class="mt-4 h-2 overflow-hidden rounded-full" style="background: var(--arcs-track);">
                                <div class="h-full rounded-full" style="width: {{ $pct }}%; background: var(--arcs-accent);"></div>
                            </div>
                            <span class="sr-only">Check-in list</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="sr-only">
            <x-todays-flights :role="'checkin'" />
        </div>
    </div>
</x-app-layout>
