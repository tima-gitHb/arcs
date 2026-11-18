@props(['station' => null])

{{-- Shown only while a station is saved. The link sends station= so the next request clears it. --}}
@if (is_string($station) && $station !== '')
    <p class="no-print text-sm text-gray-700">
        Station: {{ $station }} ·
        <a href="{{ request()->fullUrlWithQuery(['station' => '']) }}" class="text-indigo-600 hover:underline">Show all stations</a>
    </p>
@endif
