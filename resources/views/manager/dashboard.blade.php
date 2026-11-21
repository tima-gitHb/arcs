<x-app-layout>
    <x-slot name="station">
        <x-manager-station :station="$station" bar />
    </x-slot>

    @php
        $airports = \App\Support\ManagerStation::airports(request());
        $viewer = $airports->firstWhere('code', auth()->user()->airport_code);
        $localNow = $viewer ? now()->copy()->timezone($viewer->timezone) : now();
        $hour = (int) $localNow->format('G');
        $hello = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
        $firstName = \Illuminate\Support\Str::before(auth()->user()->name, ' ');
        $selectedAirport = is_string($station) && $station !== '' ? $airports->firstWhere('code', $station) : null;
        $place = $selectedAirport ? $selectedAirport->city.' station' : 'All stations';

        $avgLoad = $rows->isEmpty() ? 0 : (int) round($rows->avg('percent'));
        $flightCount = $rows->count();
        $onTimeStatuses = [
            \App\Enums\FlightStatus::Scheduled,
            \App\Enums\FlightStatus::CheckinOpen,
            \App\Enums\FlightStatus::Boarding,
            \App\Enums\FlightStatus::Closed,
            \App\Enums\FlightStatus::Departed,
        ];
        $onTime = $rows->filter(fn ($row) => in_array($row['flight']->status, $onTimeStatuses, true))->count();

        $disrupted = $rows->filter(fn ($row) => in_array($row['flight']->status, [
            \App\Enums\FlightStatus::Delayed,
            \App\Enums\FlightStatus::Cancelled,
        ], true));

        $routes = $rows
            ->groupBy(fn ($row) => $row['flight']->origin.' → '.$row['flight']->destination)
            ->map(fn ($group) => (int) $group->sum('passengers'))
            ->sortDesc();
        $topRoute = $routes->keys()->first();
        $topRoutePassengers = $routes->first();

        $loadPreview = $flightCount.' '.Str::plural('flight', $flightCount).' · average check-in '.$avgLoad.' %';
        $routePreview = $topRoute
            ? $topRoute.' · '.$topRoutePassengers.' '.Str::plural('passenger', (int) $topRoutePassengers)
            : 'No flights today.';
        $disruptionPreview = $disrupted->isEmpty()
            ? 'No delayed or cancelled flights in today\'s list.'
            : $disrupted->count().' delayed or cancelled · '.$disrupted->first()['flight']->flight_no;
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[22px] font-bold tracking-tight text-gray-900 sm:text-2xl">{{ $hello }}, {{ $firstName }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $place }} · {{ $localNow->format('l j M') }}</p>
            </div>

            {{-- Decorative only. The dashboard has no period argument, so Today, 7 days and 30 days all keep today's $rows. --}}
            <div x-data="{ period: 'today' }" class="text-right">
                <div class="inline-flex rounded-full bg-white p-1 shadow-sm" style="border: 1px solid var(--arcs-border);" role="group" aria-label="Period">
                    <button type="button" class="rounded-full px-3 py-1.5 text-sm font-medium" :class="period === 'today' ? 'text-white' : 'text-gray-600'" :style="period === 'today' ? 'background: var(--arcs-bar)' : ''" @click="period = 'today'">Today</button>
                    <button type="button" class="rounded-full px-3 py-1.5 text-sm font-medium" :class="period === '7' ? 'text-white' : 'text-gray-600'" :style="period === '7' ? 'background: var(--arcs-bar)' : ''" @click="period = '7'">7 days</button>
                    <button type="button" class="rounded-full px-3 py-1.5 text-sm font-medium" :class="period === '30' ? 'text-white' : 'text-gray-600'" :style="period === '30' ? 'background: var(--arcs-bar)' : ''" @click="period = '30'">30 days</button>
                </div>
                <p x-show="period !== 'today'" x-cloak class="mt-2 max-w-xs text-xs text-gray-500">Still today's flights. This control does not change the figures.</p>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-[14px] border bg-white px-5 py-4 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="text-sm text-gray-500">Ticket revenue</p>
                <a href="{{ route('manager.sales') }}" class="mt-2 inline-block text-2xl font-bold hover:underline" style="color: var(--arcs-accent-text);">See sales</a>
            </div>
            <div class="rounded-[14px] border bg-white px-5 py-4 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="text-sm text-gray-500">Extras: bags, upgrades</p>
                <a href="{{ route('manager.sales') }}" class="mt-2 inline-block text-2xl font-bold hover:underline" style="color: var(--arcs-accent-text);">See sales</a>
            </div>
            <div class="rounded-[14px] border bg-white px-5 py-4 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="text-sm text-gray-500">Average load factor</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $avgLoad }} %</p>
            </div>
            <div class="rounded-[14px] border bg-white px-5 py-4 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="text-sm text-gray-500">On-time departures</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $onTime }} of {{ $flightCount }}</p>
            </div>
        </div>

        <div x-data="{ loadOpen: false }" class="space-y-4">
            <p class="text-sm text-gray-500">Choose an analysis · hover to preview, click to open</p>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <a href="{{ route('manager.sales') }}" class="group block rounded-[14px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-700" style="background: #EEF0F4;" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </span>
                    <p class="mt-3 font-semibold text-gray-900">Revenue</p>
                    <p class="mt-1 text-sm text-gray-600 group-hover:hidden">Tickets against extras (bags, upgrades, equipment), day by day.</p>
                    <p class="mt-1 hidden text-sm text-gray-600 group-hover:block">No revenue figure on this page. Open sales for tickets and extras.</p>
                    <p class="mt-3 text-sm font-semibold" style="color: var(--arcs-accent-text);">Open</p>
                </a>

                <button type="button" class="group rounded-[14px] border bg-white p-5 text-left shadow-sm" style="border-color: var(--arcs-border);" @click="loadOpen = ! loadOpen" :aria-expanded="loadOpen ? 'true' : 'false'">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-700" style="background: #EEF0F4;" aria-hidden="true">
                        <span class="text-sm font-semibold">%</span>
                    </span>
                    <p class="mt-3 font-semibold text-gray-900">Flight load</p>
                    <p class="mt-1 text-sm text-gray-600 group-hover:hidden">How full each departure is, with the cabin on the plane.</p>
                    <p class="mt-1 hidden text-sm text-gray-600 group-hover:block">{{ $loadPreview }}</p>
                    <p class="mt-3 text-sm font-semibold" style="color: var(--arcs-accent-text);" x-text="loadOpen ? 'Close' : 'Open'">Open</p>
                </button>

                <a href="{{ route('manager.flights') }}" class="group block rounded-[14px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-700" style="background: #EEF0F4;" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </span>
                    <p class="mt-3 font-semibold text-gray-900">Top routes</p>
                    <p class="mt-1 text-sm text-gray-600 group-hover:hidden">Which routes and connections sell best.</p>
                    <p class="mt-1 hidden text-sm text-gray-600 group-hover:block">{{ $routePreview }}</p>
                    <p class="mt-3 text-sm font-semibold" style="color: var(--arcs-accent-text);">Open</p>
                </a>

                <a href="{{ route('manager.flights') }}" class="group block rounded-[14px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-700" style="background: #EEF0F4;" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01"/><path d="M10.3 4.8 2.6 18a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.8a2 2 0 0 0-3.4 0z"/></svg>
                    </span>
                    <p class="mt-3 font-semibold text-gray-900">Disruptions</p>
                    <p class="mt-1 text-sm text-gray-600 group-hover:hidden">Delays, gate changes and no-shows today.</p>
                    <p class="mt-1 hidden text-sm text-gray-600 group-hover:block">{{ $disruptionPreview }}</p>
                    <p class="mt-3 text-sm font-semibold" style="color: var(--arcs-accent-text);">Open</p>
                </a>

                <a href="{{ route('manager.payments') }}" class="group block rounded-[14px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-700" style="background: #EEF0F4;" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                    </span>
                    <p class="mt-3 font-semibold text-gray-900">Payments</p>
                    <p class="mt-1 text-sm text-gray-600 group-hover:hidden">Every card payment, failed ones apart.</p>
                    <p class="mt-1 hidden text-sm text-gray-600 group-hover:block">No payment totals on this page.</p>
                    <p class="mt-3 text-sm font-semibold" style="color: var(--arcs-accent-text);">Open</p>
                </a>

                <a href="{{ route('manager.audit') }}" class="group block rounded-[14px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-700" style="background: #EEF0F4;" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3"/><path d="M5 19a7 7 0 0 1 14 0"/></svg>
                    </span>
                    <p class="mt-3 font-semibold text-gray-900">Staff activity</p>
                    <p class="mt-1 text-sm text-gray-600 group-hover:hidden">Who did what, from the audit log.</p>
                    <p class="mt-1 hidden text-sm text-gray-600 group-hover:block">No staff counts on this page. Open the audit log.</p>
                    <p class="mt-3 text-sm font-semibold" style="color: var(--arcs-accent-text);">Open</p>
                </a>
            </div>

            <div x-show="loadOpen" x-cloak class="space-y-4">
                @forelse ($rows as $row)
                    @php
                        $share = fn (int $number) => $row['passengers'] > 0 ? round($number / $row['passengers'] * 100) : 0;
                    @endphp

                    <x-panel>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-gray-900">
                                <span class="arcs-mono">{{ $row['flight']->flight_no }}</span>
                                <span class="font-normal text-gray-500">{{ $row['flight']->origin }} → {{ $row['flight']->destination }}</span>
                            </h2>
                            <x-status-badge :status="$row['flight']->status" />
                        </div>

                        <dl class="mt-4 space-y-4 text-sm text-gray-700">
                            <div>
                                <div class="flex justify-between gap-3">
                                    <dt>Checked in</dt>
                                    <dd class="font-medium"><span data-figure="checked-in">{{ $row['percent'] }} %</span> ({{ $row['checked_in'] }} of {{ $row['passengers'] }})</dd>
                                </div>
                                <div class="mt-1 h-2.5 rounded-full" style="background: #EEF0F4;">
                                    <div class="h-2.5 rounded-full" style="width: {{ $row['percent'] }}%; background: var(--arcs-accent);"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between gap-3">
                                    <dt>Boarded</dt>
                                    <dd class="font-medium"><span data-figure="boarded">{{ $row['boarded'] }}</span></dd>
                                </div>
                                <div class="mt-1 h-2.5 rounded-full" style="background: #EEF0F4;">
                                    <div class="h-2.5 rounded-full" style="width: {{ $share($row['boarded']) }}%; background: var(--arcs-boarded);"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between gap-3">
                                    <dt>No-shows</dt>
                                    <dd class="font-medium"><span data-figure="no-shows">{{ $row['no_shows'] }}</span></dd>
                                </div>
                                <div class="mt-1 h-2.5 rounded-full" style="background: #EEF0F4;">
                                    <div class="h-2.5 rounded-full" style="width: {{ $share($row['no_shows']) }}%; background: var(--arcs-error);"></div>
                                </div>
                            </div>

                            <div class="flex justify-between gap-3">
                                <dt>Bags</dt>
                                <dd class="font-medium"><span data-figure="bags">{{ $row['bags'] }}</span></dd>
                            </div>
                        </dl>
                    </x-panel>
                @empty
                    <x-empty-state message="No flights today." />
                @endforelse
            </div>
        </div>

        <x-todays-flights :role="'manager'" :station="$station" />
    </div>
</x-app-layout>
