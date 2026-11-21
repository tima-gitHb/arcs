@props(['station' => null, 'control' => true, 'bar' => false])

{{-- Sales already has its own station select. The other manager pages share this one. --}}
{{-- bar: compact control for the top bar (dashboard). Same fields, no extra query. --}}
<div @class(['flex flex-wrap items-center gap-x-3 gap-y-1 text-white' => $bar, 'space-y-2' => ! $bar])>
    @if ($control)
        <form method="GET" action="{{ request()->url() }}" @class(['no-print flex items-center gap-2' => $bar, 'no-print flex flex-wrap items-end gap-3' => ! $bar])>
            @foreach (request()->except('station') as $name => $value)
                @if (is_scalar($value))
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endif
            @endforeach

            <div @class(['flex items-center gap-2' => $bar])>
                <label for="manager-station" @class(['text-xs text-white/70' => $bar, 'block text-sm font-medium text-gray-700' => ! $bar])>Station</label>
                <select id="manager-station" name="station" onchange="this.form.submit()" @class(['h-9 rounded-lg border-0 bg-white px-2 text-sm text-gray-900' => $bar, 'arcs-input mt-1 block w-40' => ! $bar])>
                    <option value="" @selected($station === null || $station === '')>All stations</option>
                    @foreach (\App\Support\ManagerStation::airports(request()) as $airport)
                        <option value="{{ $airport->code }}" @selected($station === $airport->code)>{{ $bar ? $airport->code.' · '.$airport->city : $airport->code }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    @endif

    {{-- Shown only while a station is saved. The link sends station= so the next request clears it. --}}
    @if (is_string($station) && $station !== '')
        <p @class(['no-print text-xs text-white/80' => $bar, 'no-print text-sm text-gray-700' => ! $bar])>
            Station: {{ $station }} ·
            <a href="{{ request()->fullUrlWithQuery(['station' => '']) }}" @class(['font-medium text-white underline' => $bar, 'font-medium hover:underline' => ! $bar]) @unless($bar) style="color: var(--arcs-accent-text);" @endunless>Show all stations</a>
        </p>
    @endif
</div>
