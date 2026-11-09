{{--
    Plane seat map (R1 / C3 / G6+). Drawn by window.ArcsSeatMap.draw() into #seat-map.
    <x-seat-map hint="..." mode="booking|gate" />
    mode=booking (default): free by class, selected, taken.
    mode=gate: booked / checked in / boarded / no-show colours.
--}}
@props(['hint' => '', 'mode' => 'booking'])

<aside id="seat-map-panel"
    {{ $attributes->except(['data-seats', 'data-selected-seat', 'data-cabin', 'data-seat-states'])->merge([
        'class' => 'seat-map-sticky plane-panel lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto rounded-[2rem] bg-[#14213d] px-4 py-5 text-white',
    ]) }}>
    <div class="plane-fuselage mx-auto w-full max-w-[16rem]">
        <div class="plane-nose mx-auto h-10 w-16 rounded-t-full bg-[#2c406e]"></div>
        <div class="plane-body relative rounded-b-[2rem] border border-white/15 bg-[#1a2a4a] px-2 pb-4 pt-2">
            <div class="plane-wing plane-wing-left" aria-hidden="true"></div>
            <div class="plane-wing plane-wing-right" aria-hidden="true"></div>
            <p class="text-center text-xs uppercase tracking-[0.2em] text-white/70">Cabin</p>
            <h2 class="mt-1 text-center text-lg font-semibold">Seat map</h2>
            <p id="seat-map-hint" class="mt-1 text-center text-xs text-white/70">{{ $hint }}</p>
            {{ $slot }}
            <div id="seat-map" {{ $attributes->only(['data-seats', 'data-selected-seat', 'data-cabin', 'data-seat-states'])->merge([
                'class' => 'plane-slide-in mt-4 max-h-[60vh] overflow-y-auto pr-1',
                'data-mode' => $mode,
            ]) }}></div>
        </div>
        <div class="plane-tail mx-auto mt-1 h-6 w-10 rounded-b-md bg-[#2c406e]"></div>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2 text-xs text-white/80">
        @if ($mode === 'gate')
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-white"></span> Free</span>
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-sky-300"></span> Booked</span>
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-amber-300"></span> Checked in</span>
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-emerald-400"></span> Boarded</span>
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-rose-400"></span> No-show</span>
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-slate-600"></span> Taken</span>
        @else
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-white"></span> Economy</span>
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-violet-200"></span> Premium</span>
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-amber-200"></span> Business</span>
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-emerald-400"></span> Selected</span>
            <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-slate-600"></span> Taken</span>
        @endif
    </div>
</aside>

<style>
    .plane-wing {
        position: absolute;
        top: 42%;
        width: 2.75rem;
        height: 0.55rem;
        background: #3a5080;
        border-radius: 999px;
    }
    .plane-wing-left { left: -2.4rem; transform: rotate(-8deg); }
    .plane-wing-right { right: -2.4rem; transform: rotate(8deg); }

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
