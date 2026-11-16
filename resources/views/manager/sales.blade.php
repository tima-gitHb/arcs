<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Sales per flight</h1>
    </x-slot>

    <div class="space-y-6">
        {{-- Filters. A GET form, so the choice is in the URL. Empty fields leave today's page as it is. --}}
        <div class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
            <form method="GET" action="{{ route('manager.sales') }}" class="flex flex-wrap items-end gap-4">
                <div>
                    <x-input-label for="from" value="From" />
                    <input type="date" id="from" name="from" value="{{ $filters['from'] }}" class="mt-1 block border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <x-input-error :messages="$errors->get('from')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="to" value="To" />
                    <input type="date" id="to" name="to" value="{{ $filters['to'] }}" class="mt-1 block border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <x-input-error :messages="$errors->get('to')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="route" value="Route" />
                    <select id="route" name="route" class="mt-1 block w-40 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="">All routes</option>
                        @foreach ($routes as $route)
                            <option value="{{ $route }}" @selected($filters['route'] === $route)>{{ str_replace('-', ' → ', $route) }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('route')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="station" value="Station" />
                    <select id="station" name="station" class="mt-1 block w-36 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="">All stations</option>
                        @foreach ($stations as $code)
                            <option value="{{ $code }}" @selected($filters['station'] === $code)>{{ $code }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('station')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="flight" value="Flight" />
                    <select id="flight" name="flight" class="mt-1 block w-72 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
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
                    <a data-back-to-today href="{{ route('manager.sales') }}" class="text-sm text-indigo-600 hover:underline">Back to today</a>
                @endif
            </form>

            <p data-dates-in class="mt-4 text-sm text-gray-600">Dates in {{ $timezone }}</p>
        </div>

        {{-- Ticket revenue against extras, summed from the payments already loaded for the flights above. --}}
        <section data-dashboard class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
            <h2 class="text-lg font-semibold text-navy">Revenue</h2>

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
                    <figcaption class="text-sm font-medium text-navy">Ticket revenue and extras</figcaption>
                    {{-- Width matches the viewBox, so font-size 14 is 14px and the chart does not grow with the card. --}}
                    <svg data-chart="ticket-extras" viewBox="0 0 360 88" width="360" height="88" class="mt-2" style="max-width: 100%; height: auto" role="img">
                        <title>Ticket revenue and extras</title>
                        <text x="0" y="16" font-size="14" fill="#334155">Ticket {{ \App\Support\Money::format($ticket) }}</text>
                        <rect x="0" y="24" height="12" width="{{ intdiv($ticket * 220, $ticketScale) }}" fill="#1e3a5f"></rect>
                        <text x="0" y="60" font-size="14" fill="#334155">Extras {{ \App\Support\Money::format($extrasTotal) }}</text>
                        <rect x="0" y="68" height="12" width="{{ intdiv($extrasTotal * 220, $ticketScale) }}" fill="#64748b"></rect>
                    </svg>
                </figure>

                <figure>
                    <figcaption class="text-sm font-medium text-navy">Extras by kind</figcaption>
                    <svg data-chart="extras-by-kind" viewBox="0 0 360 148" width="360" height="148" class="mt-2" style="max-width: 100%; height: auto" role="img">
                        <title>Extras by kind</title>
                        @foreach ($kinds as $label => $cents)
                            <text x="0" y="{{ $loop->iteration * 36 - 16 }}" font-size="14" fill="#334155">{{ $label }} {{ \App\Support\Money::format($cents) }}</text>
                            <rect x="0" y="{{ $loop->iteration * 36 - 10 }}" height="12" width="{{ intdiv($cents * 220, $kindScale) }}" fill="#1e3a5f"></rect>
                        @endforeach
                    </svg>
                </figure>
            </div>

            <p class="mt-4 text-sm text-gray-600">Sports equipment and pets are included in the payment they were charged with.</p>
        </section>

        {{-- Today's flights, one link each. Only on the default page: a chosen flight is shown alone. --}}
        @if ($defaultPage)
            <div class="bg-white rounded-lg shadow-sm overflow-x-auto">
                <h2 class="px-4 pt-4 text-lg font-semibold text-navy">Today's flights</h2>

                @if ($reports->isEmpty())
                    <p class="px-4 py-4 text-sm text-gray-600">No flights today.</p>
                @else
                    <table data-sales-today class="mt-2 w-full text-sm">
                        <thead class="text-left text-gray-500 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-2 font-medium">Flight</th>
                                <th class="px-4 py-2 font-medium">Route</th>
                                <th class="px-4 py-2 font-medium">Departure</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reports as $report)
                                @php
                                    $flight = $report['flight'];
                                @endphp
                                <tr data-today-flight="{{ $flight->flight_no }}" class="border-b border-gray-100 last:border-0">
                                    <td class="px-4 py-2 font-medium">{{ $flight->flight_no }}</td>
                                    <td class="px-4 py-2">{{ $flight->origin }} → {{ $flight->destination }}</td>
                                    <td class="px-4 py-2"><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" /></td>
                                    <td class="px-4 py-2 text-right">
                                        <a href="{{ route('manager.sales', ['flight' => $flight->id]) }}" class="font-medium text-navy hover:underline">Sales</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
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

            <section data-flight="{{ $flight->flight_no }}" class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
                <h2 class="text-lg font-semibold text-navy">
                    {{ $flight->flight_no }} — {{ $flight->origin }} → {{ $flight->destination }}
                </h2>
                <p class="text-sm text-gray-600"><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M Y H:i" /> · {{ $flight->status->label() }}</p>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-gray-500 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-2 font-medium">Cabin class</th>
                                <th class="px-4 py-2 font-medium">Seats sold</th>
                                <th class="px-4 py-2 font-medium">Seats</th>
                                <th class="px-4 py-2 font-medium">Load factor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                {{-- Above 100 % the flight is overbooked: shown as it is, in red. --}}
                                @php
                                    $overbooked = $row['percent'] !== null && $row['percent'] > 100;
                                @endphp
                                <tr data-class="{{ $row['key'] }}" class="border-b border-gray-100 last:border-0 {{ $row['key'] === 'Overall' ? 'font-semibold' : '' }}">
                                    <td class="px-4 py-2">{{ $row['label'] }}</td>
                                    <td class="px-4 py-2" data-cell="sold">{{ $row['sold'] }}</td>
                                    <td class="px-4 py-2" data-cell="capacity">{{ $row['capacity'] ?? '—' }}</td>
                                    <td class="px-4 py-2 {{ $overbooked ? 'text-red-600 font-semibold' : '' }}" data-cell="load" @if ($overbooked) data-overbooked="true" @endif>
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
