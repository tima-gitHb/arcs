@props(['station' => null, 'control' => true])

{{-- Sales already has its own station select. The other manager pages share this one. --}}
@if ($control)
    <form method="GET" action="{{ request()->url() }}" class="no-print flex flex-wrap items-end gap-3">
        @foreach (request()->except('station') as $name => $value)
            @if (is_scalar($value))
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach

        <div>
            <label for="manager-station" class="block text-sm font-medium text-gray-700">Station</label>
            <select id="manager-station" name="station" onchange="this.form.submit()" class="mt-1 block w-36 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                <option value="" @selected($station === null || $station === '')>All stations</option>
                @foreach (\App\Support\ManagerStation::airports(request()) as $airport)
                    <option value="{{ $airport->code }}" @selected($station === $airport->code)>{{ $airport->code }}</option>
                @endforeach
            </select>
        </div>
    </form>
@endif

{{-- Shown only while a station is saved. The link sends station= so the next request clears it. --}}
@if (is_string($station) && $station !== '')
    <p class="no-print text-sm text-gray-700">
        Station: {{ $station }} ·
        <a href="{{ request()->fullUrlWithQuery(['station' => '']) }}" class="text-indigo-600 hover:underline">Show all stations</a>
    </p>
@endif
