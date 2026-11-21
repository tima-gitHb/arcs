@props([
    'title',
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-start justify-between gap-4']) }}>
    <div class="min-w-0">
        <h1 class="text-[22px] font-bold tracking-tight text-gray-900 sm:text-2xl">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-gray-500">{{ $subtitle }}</p>
        @endif
    </div>
    @php
        $actionsHtml = isset($actions) ? trim((string) $actions) : '';
        $slotHtml = trim((string) $slot);
    @endphp
    @if ($actionsHtml !== '' || $slotHtml !== '')
        <div class="flex flex-wrap items-center gap-2">
            @if ($actionsHtml !== '')
                {{ $actions }}
            @endif
            @if ($slotHtml !== '')
                {{ $slot }}
            @endif
        </div>
    @endif
</div>
