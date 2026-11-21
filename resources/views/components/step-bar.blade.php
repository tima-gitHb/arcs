{{--
    Step bar (B14): steps with done / now / next.
    @props steps: list of labels; current: 0-based index of the active step.
--}}
@props([
    'steps' => [],
    'current' => 0,
])

<ol {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2 text-sm']) }}>
    @foreach ($steps as $index => $label)
        @php
            $state = $index < $current ? 'done' : ($index === $current ? 'now' : 'next');
        @endphp
        <li @class([
            'inline-flex items-center gap-2 rounded-full px-3 py-1 font-medium',
            'bg-emerald-100 text-emerald-800' => $state === 'done',
            'text-white' => $state === 'now',
            'bg-gray-100 text-gray-500' => $state === 'next',
        ]) @style(['background: var(--arcs-accent, #C2412D)' => $state === 'now'])>
            <span class="text-xs tabular-nums">{{ $index + 1 }}</span>
            <span>{{ $label }}</span>
        </li>
        @if (! $loop->last)
            <li class="text-gray-300" aria-hidden="true">→</li>
        @endif
    @endforeach
</ol>
