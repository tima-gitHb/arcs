@php $tab = request('tab', 'routes'); @endphp
<x-app-layout>
    <x-page-header title="Fares and fees" subtitle="Default prices, or prices for one route. Amounts are US dollars.">
        <x-slot:breadcrumb>
            <span>Fares and fees</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <div class="mb-6 flex flex-wrap gap-2">
        <a href="{{ route('manager.fares', array_filter(['route' => $route ?: null, 'tab' => 'routes'])) }}" class="rounded-full px-3 py-1.5 text-sm {{ $tab === 'routes' ? 'font-semibold text-white' : 'text-gray-600' }}" style="{{ $tab === 'routes' ? 'background: var(--arcs-bar)' : 'background: #EEF0F4' }}">Route fares</a>
        <a href="{{ route('manager.fares', ['tab' => 'trips']) }}" class="rounded-full px-3 py-1.5 text-sm {{ $tab === 'trips' ? 'font-semibold text-white' : 'text-gray-600' }}" style="{{ $tab === 'trips' ? 'background: var(--arcs-bar)' : 'background: #EEF0F4' }}">Trip fares</a>
        <a href="{{ route('manager.fares', array_filter(['route' => $route ?: null, 'tab' => 'fees'])) }}" class="rounded-full px-3 py-1.5 text-sm {{ $tab === 'fees' ? 'font-semibold text-white' : 'text-gray-600' }}" style="{{ $tab === 'fees' ? 'background: var(--arcs-bar)' : 'background: #EEF0F4' }}">Fees</a>
    </div>

    @if ($tab === 'trips')
        @if (session('status'))
            <div class="mb-6 rounded-lg border px-4 py-3 text-sm" style="border-color: #B7DFC4; background: var(--arcs-success-bg); color: var(--arcs-success);" role="status">
                {{ session('status') }}
            </div>
        @endif

        <x-panel>
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <p class="text-sm text-gray-600">A trip fare replaces the route sum only when every passenger type has one.</p>
                <a href="{{ route('manager.trip-fares.create') }}" class="arcs-btn-primary">+ New trip fare</a>
            </div>

            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Route</th>
                            <th>Cabin</th>
                            <th>Passenger type</th>
                            <th>One-way</th>
                            <th>Round-trip</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tripFares as $fare)
                            <tr>
                                <td class="arcs-mono font-medium">{{ $fare->origin_code }} → {{ $fare->destination_code }}</td>
                                <td>{{ $fare->cabin_class->label() }}</td>
                                <td>{{ $fare->passenger_type->label() }}</td>
                                <td>{{ \App\Support\Money::format($fare->one_way_cents) }}</td>
                                <td>{{ $fare->round_trip_cents !== null ? \App\Support\Money::format($fare->round_trip_cents) : '—' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('manager.trip-fares.edit', $fare) }}" class="font-medium hover:underline" style="color: var(--arcs-accent-text);">Edit →</a>
                                    <form method="POST" action="{{ route('manager.trip-fares.destroy', $fare) }}" class="ml-3 inline" onsubmit="return confirm('Delete this trip fare?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm font-medium hover:underline" style="color: var(--arcs-error);">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6"><x-empty-state message="No trip fares yet." /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>
    @else
    <x-panel>
        @if (session('status'))
            <div class="mb-6 rounded-lg border px-4 py-3 text-sm" style="border-color: #B7DFC4; background: var(--arcs-success-bg); color: var(--arcs-success);" role="status">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('manager.fares.update') }}" class="space-y-8">
            @csrf
            @method('PUT')

            <section class="space-y-4" @if ($tab === 'fees') style="display:none" @endif>
                <h2 class="text-base font-semibold text-gray-900">Fares</h2>
                <p class="text-sm text-gray-600">Ticket price in US dollars, per cabin class and passenger type.</p>

                <div>
                    <x-input-label for="route" value="Route" />
                    <select id="route" name="route" class="arcs-input mt-1 block w-full" onchange="window.location = '{{ route('manager.fares') }}' + (this.value ? '?route=' + this.value : '')">
                        <option value="" @selected($route === '')>Default prices</option>
                        @foreach ($routes as $option)
                            <option value="{{ $option['value'] }}" @selected($route === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('route')" class="mt-2" />
                    @if ($route !== '')
                        <p class="mt-2 text-sm text-gray-600">An empty cell keeps the default price. Clearing a price returns that cell to the default.</p>
                    @endif
                </div>

                <div class="arcs-table-wrap">
                    <table class="arcs-table">
                        <thead>
                            <tr>
                                <th>Cabin class</th>
                                @foreach ($types as $type)
                                    <th>{{ $type->label() }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cabins as $cabin)
                                <tr class="align-top">
                                    <th scope="row" class="text-left font-medium text-gray-800">{{ $cabin->label() }}</th>
                                    @foreach ($types as $type)
                                        @php
                                            // fares[economy][adult] in the form, fares.economy.adult in old() and the errors.
                                            $cabinKey = strtolower($cabin->value);
                                            $typeKey = strtolower($type->value);
                                            $field = "fares.$cabinKey.$typeKey";
                                        @endphp
                                        <td>
                                            <div class="flex items-center gap-1">
                                                <span class="text-gray-600">$</span>
                                                <x-text-input id="fare_{{ $cabinKey }}_{{ $typeKey }}" name="fares[{{ $cabinKey }}][{{ $typeKey }}]" type="number" min="0" max="99999.99" step="0.01" class="w-28"
                                                    :value="old($field, $fares[$cabinKey.'.'.$typeKey] ?? '')"
                                                    :placeholder="$route !== '' ? ($defaults[$cabinKey.'.'.$typeKey] ?? '') : null"
                                                    :required="$route === ''"
                                                    aria-label="{{ $cabin->label() }} {{ $type->label() }} fare in dollars" />
                                            </div>
                                            <x-input-error :messages="$errors->get($field)" class="mt-2" />
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-4" @if ($tab === 'routes') style="display:none" @endif>
                <h2 class="text-base font-semibold text-gray-900">Fees</h2>
                <p class="text-sm text-gray-600">Price of each extra in US dollars. Excess baggage is charged per kg.</p>

                <div class="grid gap-6 sm:grid-cols-2">
                    @foreach ($feeCodes as $code)
                        <div>
                            <x-input-label for="fee_{{ $code->value }}" :value="$code->label()" />
                            <div class="mt-1 flex items-center gap-1">
                                <span class="text-gray-600">$</span>
                                <x-text-input id="fee_{{ $code->value }}" name="fees[{{ $code->value }}]" type="number" min="0" max="99999.99" step="0.01" class="w-28"
                                    :value="old('fees.'.$code->value, $fees[$code->value] ?? '')" required />
                            </div>
                            <x-input-error :messages="$errors->get('fees.'.$code->value)" class="mt-2" />
                        </div>
                    @endforeach
                </div>
            </section>

            <div>
                <x-primary-button>Save fares and fees</x-primary-button>
            </div>
        </form>
    </x-panel>
    @endif
</x-app-layout>
