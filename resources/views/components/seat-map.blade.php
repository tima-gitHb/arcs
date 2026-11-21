{{--
    Plane seat map (R1 / C3 / G6+). Drawn by window.ArcsSeatMap.draw() into #seat-map.
    <x-seat-map hint="..." mode="booking|gate" />
    Navy panel + white fuselage — keep seat-map-panel / seat-map-sticky / plane-fuselage for PlaneSeatMapTest.
--}}
@props(['hint' => '', 'mode' => 'booking'])

<aside id="seat-map-panel"
    {{ $attributes->except(['data-seats', 'data-selected-seat', 'data-cabin', 'data-seat-states'])->merge([
        'class' => 'seat-map-sticky plane-panel arcs-seat-map-panel lg:sticky lg:top-4',
    ]) }}
    style="background: var(--arcs-seat-panel);">
    <div class="mb-4 text-center">
        <p class="text-[10px] font-semibold uppercase tracking-[0.22em]" style="color: var(--arcs-seat-muted);">Cabin</p>
        <h2 class="mt-1 text-lg font-semibold text-white">Seat map</h2>
        <p id="seat-map-hint" class="mt-1 text-xs" style="color: var(--arcs-seat-muted);">{{ $hint }}</p>
    </div>

    {{ $slot }}

    <div class="plane-fuselage arcs-plane-fuselage mx-auto">
        <div class="plane-nose arcs-plane-nose" aria-hidden="true"></div>
        <div class="plane-body arcs-plane-body">
            <div id="seat-map" {{ $attributes->only(['data-seats', 'data-selected-seat', 'data-cabin', 'data-seat-states'])->merge([
                'class' => 'plane-slide-in arcs-seat-map-scroll',
                'data-mode' => $mode,
            ]) }}></div>
        </div>
    </div>

    <div class="arcs-seat-legend mt-5 flex flex-wrap justify-center gap-2">
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
            <span class="arcs-seat-legend-pill arcs-seat-legend-legroom">Extra legroom</span>
        @endif
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
