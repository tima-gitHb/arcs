{{--
    Step bar: steps with done / now / next.
    @props steps: list of labels; current: 0-based index.
    navigate: when true, every step is clickable and follows the parent Alpine `step`.
--}}
@props([
    'steps' => [],
    'current' => 0,
    'navigate' => false,
])

<ol {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2 text-sm']) }} data-arcs-steps>
    @foreach ($steps as $index => $label)
        @php
            $state = $index < $current ? 'done' : ($index === $current ? 'now' : 'next');
        @endphp
        <li>
            <button
                type="button"
                data-step-index="{{ $index }}"
                @unless ($navigate) @disabled($state === 'next') @endunless
                @if ($navigate) x-on:click="step = {{ $index }}" @endif
                @class([
                    'inline-flex min-h-[44px] items-center gap-2 rounded-full px-3 py-1.5 font-medium',
                    'cursor-pointer' => $navigate || $state === 'done',
                    'cursor-default' => ! $navigate && $state !== 'done',
                    'bg-emerald-100 text-emerald-800' => ! $navigate && $state === 'done',
                    'text-white' => ! $navigate && $state === 'now',
                    'bg-gray-100 text-gray-500' => ! $navigate && $state === 'next',
                ])
                @if ($navigate)
                    :class="step === {{ $index }} ? 'text-white' : (step > {{ $index }} ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-500')"
                    :style="step === {{ $index }} ? 'background: var(--arcs-accent)' : ''"
                @elseif ($state === 'now')
                    style="background: var(--arcs-accent)"
                @endif
            >
                <span
                    class="text-[10px] font-semibold uppercase tracking-wider opacity-80"
                    @if ($navigate) x-text="step > {{ $index }} ? 'DONE' : (step === {{ $index }} ? 'NOW' : 'STEP')" @endif
                >
                    @if ($state === 'done') DONE @elseif ($state === 'now') NOW @else STEP @endif
                </span>
                <span class="tabular-nums">{{ $index + 1 }}</span>
                <span>{{ $label }}</span>
            </button>
        </li>
        @if (! $loop->last)
            <li class="text-gray-300" aria-hidden="true">→</li>
        @endif
    @endforeach
</ol>
