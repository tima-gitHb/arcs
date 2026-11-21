<x-app-layout>
    <x-page-header title="Sales per flight" subtitle="Ticket revenue and load for the flights in this filter." />

    <div class="space-y-6">
        <x-manager-station :station="$filters['station']" :control="false" />

        {{-- Filters. A GET form, so the choice is in the URL. Empty fields leave today's page as it is. --}}
        <x-panel>
            <form method="GET" action="{{ route('manager.sales') }}" class="flex flex-wrap items-end gap-4">
                <div>
                    <x-input-label for="from" value="From" />
                    <input type="date" id="from" name="from" value="{{ $filters['from'] }}" class="arcs-input mt-1 block w-44">
                    <x-input-error :messages="$errors->get('from')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="to" value="To" />
                    <input type="date" id="to" name="to" value="{{ $filters['to'] }}" class="arcs-input mt-1 block w-44">
                    <x-input-error :messages="$errors->get('to')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="route" value="Route" />
                    <select id="route" name="route" class="arcs-input mt-1 block w-40">
                        <option value="">All routes</option>
                        @foreach ($routes as $route)
                            <option value="{{ $route }}" @selected($filters['route'] === $route)>{{ str_replace('-', ' → ', $route) }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('route')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="station" value="Station" />
                    <select id="station" name="station" class="arcs-input mt-1 block w-36">
                        <option value="">All stations</option>
                        @foreach ($stations as $code)
                            <option value="{{ $code }}" @selected($filters['station'] === $code)>{{ $code }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('station')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="flight" value="Flight" />
                    <select id="flight" name="flight" class="arcs-input mt-1 block w-72">
                        <option value="">Choose a flight</option>
                        @foreach ($flights as $flight)
                            <option value="{{ $flight->id }}" @selected($selected?->is($flight))>
                                {{ $flight->flight_no }} — {{ $flight->origin }} → {{ $flight->destination }}, {{ $flight->departureLocal()->format('d M Y H:i') }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('flight')" class="mt-2" />
                </div>

                <x-primary-button>Show sales</x-primary-button>

                @if ($selected)
                    <a data-back-to-today href="{{ route('manager.sales') }}" class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Back to today</a>
                @endif
            </form>

            <p data-dates-in class="mt-4 text-sm text-gray-600">Dates in {{ $timezone }}</p>
        </x-panel>

        {{-- Ticket revenue against extras, summed from the payments already loaded for the flights above. --}}
        <section data-dashboard class="rounded-[14px] border bg-white p-5 text-gray-700 shadow-sm sm:p-6" style="border-color: var(--arcs-border);">
            <h2 class="text-base font-semibold text-gray-900">Revenue</h2>

            @php
                $lines = [
                    'Fare' => 'Ticket revenue',
                    'ExtraBag' => 'Extra bag',
                    'ExcessBaggage' => 'Excess baggage',
                    'Fee' => 'Fee',
                    'Upgrade' => 'Cabin upgrade',
                    'extras' => 'Extras total',
                    'grand' => 'Grand total',
                ];
                $ticket = $dashboard['Fare'];
                $extrasTotal = $dashboard['extras'];
                $ticketScale = max($ticket, $extrasTotal, 1);
                $kinds = [
                    'Extra bag' => $dashboard['ExtraBag'],
                    'Excess baggage' => $dashboard['ExcessBaggage'],
                    'Fee' => $dashboard['Fee'],
                    'Cabin upgrade' => $dashboard['Upgrade'],
                ];
                $kindScale = max(max($kinds), 1);
            @endphp

            <dl class="mt-4 grid gap-4 sm:grid-cols-4">
                @foreach ($lines as $key => $label)
                    <div>
                        <dt class="text-sm text-gray-600">{{ $label }}</dt>
                        <dd class="text-lg font-semibold" data-total="{{ $key }}">{{ \App\Support\Money::format($dashboard[$key]) }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <figure>
                    <figcaption class="text-sm font-medium text-gray-900">Ticket revenue and extras</figcaption>
                    {{-- Width matches the viewBox, so font-size 14 is 14px and the chart does not grow with the card. --}}
                    <svg data-chart="ticket-extras" viewBox="0 0 360 88" width="360" height="88" class="mt-2" style="max-width: 100%; height: auto" role="img">
                        <title>Ticket revenue and extras</title>
                        <text x="0" y="16" font-size="14" fill="#3A4156">Ticket {{ \App\Support\Money::format($ticket) }}</text>
                        <rect x="0" y="24" height="12" width="{{ intdiv($ticket * 220, $ticketScale) }}" fill="#1E2230"></rect>
                        <text x="0" y="60" font-size="14" fill="#3A4156">Extras {{ \App\Support\Money::format($extrasTotal) }}</text>
                        <rect x="0" y="68" height="12" width="{{ intdiv($extrasTotal * 220, $ticketScale) }}" fill="#4A5268"></rect>
                    </svg>
                </figure>

                <figure>
                    <figcaption class="text-sm font-medium text-gray-900">Extras by kind</figcaption>
                    <svg data-chart="extras-by-kind" viewBox="0 0 360 148" width="360" height="148" class="mt-2" style="max-width: 100%; height: auto" role="img">
                        <title>Extras by kind</title>
                        @foreach ($kinds as $label => $cents)
                            <text x="0" y="{{ $loop->iteration * 36 - 16 }}" font-size="14" fill="#3A4156">{{ $label }} {{ \App\Support\Money::format($cents) }}</text>
                            <rect x="0" y="{{ $loop->iteration * 36 - 10 }}" height="12" width="{{ intdiv($cents * 220, $kindScale) }}" fill="#1E2230"></rect>
                        @endforeach
                    </svg>
                </figure>
            </div>

            <p class="mt-4 text-sm text-gray-600">Sports equipment and pets are included in the payment they were charged with.</p>
        </section>

        {{-- Today's flights, one link each. Only on the default page: a chosen flight is shown alone. --}}
        @if ($defaultPage)
            <x-panel title="Today's flights">
                @if ($reports->isEmpty())
                    <p class="text-sm text-gray-600">No flights today.</p>
                @else
                    <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                        <table data-sales-today class="arcs-table">
                            <thead>
                                <tr>
                                    <th>Flight</th>
                                    <th>Route</th>
                                    <th>Departure</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($reports as $report)
                                    @php
                                        $flight = $report['flight'];
                                    @endphp
                                    <tr data-today-flight="{{ $flight->flight_no }}">
                                        <td class="font-medium arcs-mono">{{ $flight->flight_no }}</td>
                                        <td>{{ $flight->origin }} → {{ $flight->destination }}</td>
                                        <td><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" /></td>
                                        <td class="text-right">
                                            <a href="{{ route('manager.sales', ['flight' => $flight->id]) }}" class="font-medium hover:underline" style="color: var(--arcs-accent-text);">Sales</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-panel>
        @endif

        {{-- One block per flight. --}}
        @foreach ($reports as $report)
            @php
                $flight = $report['flight'];

                // Rows of the table: the three classes, then "Class not set" (only when there are such passengers), then the total.
                $rows = [];
                foreach ($report['classes'] as $name => $line) {
                    $rows[] = ['key' => $name, 'label' => $name] + $line;
                }
                if ($report['not_set'] > 0) {
                    $rows[] = ['key' => 'NotSet', 'label' => 'Class not set', 'sold' => $report['not_set'], 'capacity' => null, 'percent' => null];
                }
                $rows[] = ['key' => 'Overall', 'label' => 'Overall'] + $report['overall'];
            @endphp

            <section data-flight="{{ $flight->flight_no }}" class="rounded-[14px] border bg-white p-5 text-gray-700 shadow-sm sm:p-6" style="border-color: var(--arcs-border);">
                <h2 class="text-base font-semibold text-gray-900">
                    {{ $flight->flight_no }} — {{ $flight->origin }} → {{ $flight->destination }}
                </h2>
                <p class="text-sm text-gray-600"><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M Y H:i" /> · {{ $flight->status->label() }}</p>

                <div class="arcs-table-wrap mt-4">
                    <table class="arcs-table">
                        <thead>
                            <tr>
                                <th>Cabin class</th>
                                <th>Seats sold</th>
                                <th>Seats</th>
                                <th>Load factor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                {{-- Above 100 % the flight is overbooked: shown as it is, in red. --}}
                                @php
                                    $overbooked = $row['percent'] !== null && $row['percent'] > 100;
                                @endphp
                                <tr data-class="{{ $row['key'] }}" @class(['font-semibold' => $row['key'] === 'Overall'])>
                                    <td>{{ $row['label'] }}</td>
                                    <td data-cell="sold">{{ $row['sold'] }}</td>
                                    <td data-cell="capacity">{{ $row['capacity'] ?? '—' }}</td>
                                    <td data-cell="load" @class(['text-red-600 font-semibold' => $overbooked]) @if ($overbooked) data-overbooked="true" @endif>
                                        {{ $row['percent'] === null ? '—' : $row['percent'].' %' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <dl class="mt-6 grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-sm text-gray-600">Fares</dt>
                        <dd class="text-lg font-semibold" data-revenue="fares">{{ \App\Support\Money::format($report['revenue']['fares_cents']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-600">Fees</dt>
                        <dd class="text-lg font-semibold" data-revenue="fees">{{ \App\Support\Money::format($report['revenue']['fees_cents']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-600">Total revenue</dt>
                        <dd class="text-lg font-semibold" data-revenue="total">{{ \App\Support\Money::format($report['revenue']['total_cents']) }}</dd>
                    </div>
                </dl>
            </section>
        @endforeach
    </div>
</x-app-layout>
