<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Flight lists</h1>
    </x-slot>

    {{-- Print styles: the browser's own print (window.print()) shows the page without the header, menu and buttons. --}}
    <style>
        @media print {
            header, nav, .no-print { display: none !important; }
            body { background: #fff !important; }
            main { padding: 0 !important; }
            .print-card { box-shadow: none !important; }
        }
    </style>

    <div class="space-y-6">
        {{-- Flight picker: a GET form, so the chosen flight (and tab) is in the URL. --}}
        <form method="GET" action="{{ route('manager.home') }}" class="no-print bg-white rounded-lg shadow-sm p-6 flex flex-wrap items-end gap-4">
            <div>
                <x-input-label for="flight" value="Flight" />
                <select id="flight" name="flight" class="mt-1 block w-64 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" onchange="this.form.submit()">
                    <option value="" @selected($flight === null)>Choose a flight</option>
                    @foreach ($flights as $option)
                        <option value="{{ $option->id }}" @selected($flight?->is($option))>{{ $option->flight_no }} — {{ $option->departureLocal()->format('d M Y') }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Keep the open tab when another flight is chosen. --}}
            @if ($flight)
                <input type="hidden" name="tab" value="{{ $tab }}">
            @endif

            <x-primary-button>Show</x-primary-button>
        </form>

        @if ($flight)
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-navy">{{ $flight->flight_no }} · {{ $flight->origin }} → {{ $flight->destination }}</h2>
                        <p class="text-sm text-gray-600">Departs <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M Y H:i" /> · {{ $flight->status->label() }}</p>
                    </div>

                    <button type="button" onclick="window.print()" class="no-print rounded border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Print</button>
                </div>

                {{-- Tabs: plain links, so each list has its own URL. --}}
                <nav class="no-print flex gap-1 border-b border-gray-200" aria-label="Lists">
                    @foreach (['passengers' => 'Passengers', 'baggage' => 'Baggage', 'services' => 'Special services'] as $key => $label)
                        <a href="{{ route('manager.home', ['flight' => $flight->id, 'tab' => $key]) }}"
                           @class([
                               'px-4 py-2 text-sm -mb-px border-b-2',
                               'border-navy text-navy font-medium' => $tab === $key,
                               'border-transparent text-gray-600 hover:text-navy' => $tab !== $key,
                           ])
                           @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>

                {{-- One partial per list, so the PDF export can reuse them later. --}}
                @if ($tab === 'passengers')
                    @include('manager.flight-lists.passengers', ['passengers' => $passengers])
                @elseif ($tab === 'baggage')
                    @include('manager.flight-lists.baggage', ['bags' => $bags, 'bagCount' => $bagCount, 'totalWeight' => $totalWeight])
                @else
                    @include('manager.flight-lists.services', ['services' => $services])
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
