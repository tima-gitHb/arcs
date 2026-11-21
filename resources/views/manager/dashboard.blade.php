<x-app-layout>
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
        $periodLabel = match ($period) {
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            default => 'Today',
        };
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
    @endphp

    <x-page-header :title="$hello.', '.$firstName" :subtitle="$place.' · '.$localNow->format('l j M').' · '.$periodLabel">
        <x-slot:breadcrumb>
            <span>Dashboard</span>
        </x-slot:breadcrumb>
        <div class="inline-flex rounded-full bg-white p-1 shadow-sm" style="border: 1px solid var(--arcs-border);" role="group" aria-label="Period">
            @foreach (['today' => 'Today', '7d' => '7 days', '30d' => '30 days'] as $key => $label)
                <a href="{{ route('manager.dashboard', ['period' => $key]) }}"
                    class="rounded-full px-3 py-1.5 text-sm font-medium {{ $period === $key ? 'text-white' : 'text-gray-600' }}"
                    style="{{ $period === $key ? 'background: var(--arcs-bar)' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
    </x-page-header>

    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['key' => 'ticket', 'label' => 'Ticket revenue', 'value' => $kpis['ticket'], 'href' => route('manager.sales'), 'link' => 'Sales', 'delta' => $kpis['deltas']['ticket']],
                ['key' => 'extras', 'label' => 'Extras: bags, upgrades', 'value' => $kpis['extras'], 'href' => route('manager.sales'), 'link' => 'Extras', 'delta' => $kpis['deltas']['extras']],
                ['key' => 'load', 'label' => 'Average load factor', 'value' => $kpis['avg_load'].' %', 'href' => route('manager.sales'), 'link' => 'Loads', 'delta' => $kpis['deltas']['avg_load']],
                ['key' => 'ontime', 'label' => 'On-time departures', 'value' => $kpis['on_time'].' of '.$kpis['flight_count'], 'href' => route('manager.flights'), 'link' => 'Flights', 'delta' => $kpis['deltas']['on_time']],
            ] as $tile)
                <div class="rounded-[22px] border bg-white px-5 py-4 shadow-sm" style="border-color: var(--arcs-border);">
                    <p class="text-sm text-gray-500">{{ $tile['label'] }}</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900" data-kpi="{{ $tile['key'] }}">{{ $tile['value'] }}</p>
                    <p class="mt-2">
                        <span data-kpi-delta="{{ $tile['key'] }}" class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            style="background: {{ $tile['delta']['direction'] === 'up' ? '#E6F4EA' : ($tile['delta']['direction'] === 'down' ? '#FCE8E6' : '#EEF0F4') }};
                                   color: {{ $tile['delta']['direction'] === 'up' ? 'var(--arcs-success)' : ($tile['delta']['direction'] === 'down' ? 'var(--arcs-error)' : '#6B7280') }};">
                            {{ $tile['delta']['label'] }}
                        </span>
                    </p>
                    <a href="{{ $tile['href'] }}" class="mt-2 inline-block text-sm font-semibold hover:underline" style="color: var(--arcs-accent-text);">{{ $tile['link'] }}</a>
                </div>
            @endforeach
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <a href="{{ route('manager.sales') }}" class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="font-semibold text-gray-900">Revenue</p>
                <p class="mt-1 text-sm text-gray-600">Tickets against extras for this period.</p>
            </a>
            <a href="{{ route('manager.sales') }}" class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="font-semibold text-gray-900">Flight load</p>
                <p class="mt-1 text-sm text-gray-600">Average check-in {{ $kpis['avg_load'] }} % across {{ $kpis['flight_count'] }} flights.</p>
            </a>
            <a href="{{ route('manager.flights') }}" class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="font-semibold text-gray-900">Top routes</p>
                <p class="mt-1 text-sm text-gray-600">{{ $topRoute ? $topRoute.' · '.$topRoutePassengers.' passengers' : 'No flights today.' }}</p>
            </a>
            <a href="{{ route('manager.flights', ['status' => 'Delayed']) }}" class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="font-semibold text-gray-900">Disruptions</p>
                <p class="mt-1 text-sm text-gray-600">{{ $disrupted->isEmpty() ? 'No delayed or cancelled flights today.' : $disrupted->count().' delayed or cancelled' }}</p>
            </a>
            <a href="{{ route('manager.payments') }}" class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="font-semibold text-gray-900">Payments</p>
                <p class="mt-1 text-sm text-gray-600">Card charges and failed attempts.</p>
            </a>
            <a href="{{ route('manager.audit') }}" class="rounded-[22px] border bg-white p-5 shadow-sm" style="border-color: var(--arcs-border);">
                <p class="font-semibold text-gray-900">Staff activity</p>
                <p class="mt-1 text-sm text-gray-600">Who changed what in the audit log.</p>
            </a>
        </div>

        <x-panel title="Needs attention">
            <ul class="space-y-2 text-sm text-gray-700">
                @forelse ($needsAttention['boarding'] as $flight)
                    <li>
                        <a href="{{ route('manager.flights.show', $flight) }}" class="font-medium hover:underline arcs-mono" style="color: var(--arcs-accent-text);">{{ $flight->flight_no }}</a>
                        — boarding with passengers not yet boarded
                    </li>
                @empty
                    <li class="text-gray-500">No boarding gaps right now.</li>
                @endforelse
                @foreach ($needsAttention['checkin_closing'] as $flight)
                    <li>
                        <a href="{{ route('manager.flights.show', $flight) }}" class="font-medium hover:underline arcs-mono" style="color: var(--arcs-accent-text);">{{ $flight->flight_no }}</a>
                        — check-in closes within 30 minutes
                    </li>
                @endforeach
                <li>Failed payments today: <span class="font-semibold">{{ $needsAttention['failed_payments'] }}</span>
                    <a href="{{ route('manager.payments', ['status' => 'failed', 'date' => now()->toDateString()]) }}" class="ml-2 font-medium hover:underline" style="color: var(--arcs-accent-text);">Open</a>
                </li>
            </ul>
        </x-panel>

        {{-- Compact today's departures: keeps SR-47 / station-filter assertions (data-role + data-figure). --}}
        <div class="arcs-card overflow-hidden" data-role="manager">
            <h2 class="border-b px-5 py-4 text-base font-semibold text-gray-900" style="border-color: var(--arcs-border);">Today's departures</h2>
            @if ($rows->isEmpty())
                <p class="px-5 py-6 text-sm text-gray-600">No flights today.</p>
            @else
                <div class="arcs-table-wrap">
                    <table class="arcs-table">
                        <thead>
                            <tr>
                                <th>Flight</th>
                                <th>Route</th>
                                <th>Checked in</th>
                                <th>Boarded</th>
                                <th>No-shows</th>
                                <th>Bags</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="arcs-mono font-medium">
                                        <a href="{{ route('manager.flights.show', $row['flight']) }}" class="hover:underline">{{ $row['flight']->flight_no }}</a>
                                    </td>
                                    <td>{{ $row['flight']->origin }} → {{ $row['flight']->destination }}</td>
                                    <td><span data-figure="checked-in">{{ $row['percent'] }} %</span> ({{ $row['checked_in'] }} of {{ $row['passengers'] }})</td>
                                    <td><span data-figure="boarded">{{ $row['boarded'] }}</span></td>
                                    <td><span data-figure="no-shows">{{ $row['no_shows'] }}</span></td>
                                    <td><span data-figure="bags">{{ $row['bags'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
