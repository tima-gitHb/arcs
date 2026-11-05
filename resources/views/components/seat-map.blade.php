{{--
    Seat map panel, shared by reservation (R1) and check-in (C3).
    The seats are drawn into #seat-map by window.ArcsSeatMap.draw() (resources/js/seat-map.js),
    with data from App\Services\SeatService::seatMap().

    <x-seat-map hint="..." class="md:sticky" data-seats="...">  {{ errors, if any }}  </x-seat-map>
    Extra attributes (data-*) go on the #seat-map element; "class" goes on the panel.
--}}
@props(['hint' => ''])

<aside id="seat-map-panel" @class(['rounded-[2rem] bg-[#14213d] px-4 py-5 text-white', $attributes->get('class')])>
    <div class="mx-auto mb-3 h-8 w-20 rounded-t-full bg-[#2c406e]"></div>
    <p class="text-center text-xs uppercase tracking-[0.2em] text-white/70">Cabin</p>
    <h2 class="mt-1 text-center text-lg font-semibold">Seat map</h2>
    <p id="seat-map-hint" class="mt-1 text-center text-xs text-white/70">{{ $hint }}</p>
    {{ $slot }}
    <div id="seat-map" {{ $attributes->except('class')->merge(['class' => 'mt-4 max-h-[70vh] overflow-y-auto pr-1']) }}></div>
    <div class="mt-4 grid grid-cols-2 gap-2 text-xs text-white/80">
        <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-white"></span> Economy</span>
        <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-violet-200"></span> Premium</span>
        <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-amber-200"></span> Business</span>
        <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-emerald-400"></span> Selected</span>
        <span class="flex items-center gap-2"><span class="inline-block h-4 w-4 rounded bg-slate-600"></span> Taken</span>
    </div>
</aside>
