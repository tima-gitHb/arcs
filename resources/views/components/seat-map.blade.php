{{--
    Plane seat map (R1 / C3 / G6+). Drawn by window.ArcsSeatMap.draw() into #seat-map.
    <x-seat-map hint="..." mode="booking|gate" />
    Navy panel + fuselage — keep seat-map-panel / seat-map-sticky / plane-fuselage for PlaneSeatMapTest.
--}}
@props(['hint' => '', 'mode' => 'booking'])

@php
    $seatDataAttrs = [
        'data-seats',
        'data-selected-seat',
        'data-cabin',
        'data-seat-states',
        'data-pax-type',
        'data-pax-initials',
    ];
@endphp

<aside id="seat-map-panel"
    {{ $attributes->except($seatDataAttrs)->merge([
        'class' => 'seat-map-sticky plane-panel arcs-seat-map-panel lg:sticky lg:top-4',
    ]) }}
    style="background: linear-gradient(160deg, #1B2440, #24325A);">
    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-white">Seat map</h2>
            <p id="seat-map-hint" class="mt-0.5 text-xs text-white/70">{{ $hint }}</p>
        </div>
    </div>

    <div class="arcs-seat-legend mb-4 flex flex-wrap items-center gap-x-4 gap-y-2">
        @if ($mode === 'gate')
            <span class="arcs-seat-legend-pill arcs-seat-legend-available">Free</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-booked">Booked</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-checked">Checked in</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-boarded">Boarded</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-noshow">No-show</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-taken">Taken</span>
        @else
            {{-- Order Economy → Premium → Business → Selected → Taken kept for B12 assertSeeInOrder. --}}
            <span class="arcs-seat-legend-pill arcs-seat-legend-available">Economy</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-premium bg-violet-200">Premium</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-business">Business</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-selected">Selected</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-taken">Taken</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-selected">This passenger</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-party">Others in booking</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-legroom">Extra legroom</span>
            <span class="arcs-seat-legend-pill arcs-seat-legend-blocked">Not allowed</span>
        @endif
    </div>

    {{ $slot }}

    <div class="plane-fuselage arcs-plane-fuselage mx-auto">
        <div class="plane-nose arcs-plane-nose" aria-hidden="true"></div>
        <div class="plane-body arcs-plane-body">
            <div id="seat-map" {{ $attributes->only($seatDataAttrs)->merge([
                'class' => 'plane-slide-in arcs-seat-map-scroll arcs-seat-map-h',
                'data-mode' => $mode,
                'data-layout' => 'horizontal',
            ]) }}></div>
        </div>
    </div>
</aside>

<style>
    @keyframes plane-slide-in {
        from { opacity: 0; transform: translateX(1.25rem); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes seat-pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.08); }
    }
    .plane-slide-in {
        animation: plane-slide-in 0.45s ease-out both;
    }
    .seat-pulse {
        animation: seat-pulse 0.55s ease-in-out 1;
    }
    @media (prefers-reduced-motion: reduce) {
        .plane-slide-in, .seat-pulse { animation: none !important; }
    }
</style>
