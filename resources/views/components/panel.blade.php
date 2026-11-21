{{-- White card with optional title (B14 / UI). --}}
@props(['title' => null])

<section {{ $attributes->merge(['class' => 'rounded-[14px] border bg-white p-5 sm:p-6 shadow-sm']) }} style="border-color: var(--arcs-border);">
    @if ($title)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">{{ $title }}</h2>
            @isset($actions)
                <div class="flex flex-wrap gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @elseif (isset($actions))
        <div class="mb-4 flex justify-end">{{ $actions }}</div>
    @endif
    {{ $slot }}
</section>
