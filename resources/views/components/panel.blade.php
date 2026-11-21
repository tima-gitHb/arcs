{{-- White card with a title (B14 shared UI). --}}
@props(['title' => null])

<section {{ $attributes->merge(['class' => 'rounded-lg bg-white p-6 shadow-sm']) }}>
    @if ($title)
        <h2 class="mb-4 text-lg font-semibold text-gray-900">{{ $title }}</h2>
    @endif
    {{ $slot }}
</section>
