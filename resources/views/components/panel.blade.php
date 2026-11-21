{{-- Soft white card (UI2). --}}
@props(['title' => null])

<section {{ $attributes->merge(['class' => 'arcs-card p-5 sm:p-6']) }}>
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
