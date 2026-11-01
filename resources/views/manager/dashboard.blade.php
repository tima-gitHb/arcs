<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Dashboard</h1>
    </x-slot>

    <div class="space-y-6">
        <x-todays-flights :role="'manager'" />

        @foreach ($rows as $row)
            @php
                // Width of each bar: its share of the flight's passengers, in percent.
                $share = fn (int $number) => $row['passengers'] > 0 ? round($number / $row['passengers'] * 100) : 0;
            @endphp

            <div class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
                <h2 class="text-lg font-semibold text-navy">
                    {{ $row['flight']->flight_no }}
                    <span class="font-normal text-gray-500">{{ $row['flight']->origin }} → {{ $row['flight']->destination }}</span>
                </h2>

                <dl class="mt-4 space-y-4 text-sm">
                    <div>
                        <div class="flex justify-between">
                            <dt>Checked in</dt>
                            <dd class="font-medium"><span data-figure="checked-in">{{ $row['percent'] }} %</span> ({{ $row['checked_in'] }} of {{ $row['passengers'] }})</dd>
                        </div>
                        <div class="mt-1 h-3 rounded bg-gray-100">
                            <div class="h-3 rounded bg-blue-600" style="width: {{ $row['percent'] }}%"></div>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between">
                            <dt>Boarded</dt>
                            <dd class="font-medium"><span data-figure="boarded">{{ $row['boarded'] }}</span></dd>
                        </div>
                        <div class="mt-1 h-3 rounded bg-gray-100">
                            <div class="h-3 rounded bg-green-600" style="width: {{ $share($row['boarded']) }}%"></div>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between">
                            <dt>No-shows</dt>
                            <dd class="font-medium"><span data-figure="no-shows">{{ $row['no_shows'] }}</span></dd>
                        </div>
                        <div class="mt-1 h-3 rounded bg-gray-100">
                            <div class="h-3 rounded bg-red-600" style="width: {{ $share($row['no_shows']) }}%"></div>
                        </div>
                    </div>

                    <div class="flex justify-between">
                        <dt>Bags</dt>
                        <dd class="font-medium"><span data-figure="bags">{{ $row['bags'] }}</span></dd>
                    </div>
                </dl>
            </div>
        @endforeach
    </div>
</x-app-layout>
