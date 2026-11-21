@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'text-sm font-medium']) }} style="color: var(--arcs-success);" role="status">
        {{ $status }}
    </div>
@endif
