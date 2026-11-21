@props([
    'message' => 'Nothing here yet.',
])

<div {{ $attributes->merge(['class' => 'rounded-[14px] border bg-white px-6 py-10 text-center']) }} style="border-color: var(--arcs-border);">
    <p class="text-sm text-gray-600">{{ $message }}</p>
    @if (trim($slot ?? '') !== '')
        <div class="mt-4 flex justify-center">{{ $slot }}</div>
    @endif
</div>
