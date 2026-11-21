{{--
    Docked plane seat map for the app-shell aside (B14). Wraps the existing <x-seat-map>;
    do not build a second seat map.
--}}
@props([
    'hint' => '',
    'mode' => 'booking',
])

<div class="arcs-seat-map-dock">
    <x-seat-map :hint="$hint" :mode="$mode" {{ $attributes }}>
        {{ $slot }}
    </x-seat-map>
</div>
