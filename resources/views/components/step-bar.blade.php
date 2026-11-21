{{--
    UI2 step chips: done = dark + ✓, current = accent, next = white.
    @props steps, current (0-based), navigate (Alpine step), hashes optional.
--}}
@props([
    'steps' => [],
    'current' => 0,
    'navigate' => false,
    'hashes' => [],
])

<ol {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2 text-sm']) }} data-arcs-steps>
    @foreach ($steps as $index => $label)
        @php
            $state = $index < $current ? 'done' : ($index === $current ? 'now' : 'next');
            $hash = $hashes[$index] ?? null;
        @endphp
        <li class="flex items-center gap-2">
            <button
                type="button"
                data-step-index="{{ $index }}"
                @if ($hash) data-step-hash="{{ $hash }}" @endif
                @unless ($navigate) @disabled($state === 'next') @endunless
                @if ($navigate)
                    x-on:click="step = {{ $index }}; if (window.arcsBookingSetHash) window.arcsBookingSetHash({{ $index }})"
                @endif
                @class([
                    'inline-flex min-h-[40px] items-center gap-2 rounded-full px-3.5 py-1.5 font-semibold shadow-sm',
                    'cursor-pointer' => $navigate || $state === 'done',
                    'cursor-default' => ! $navigate && $state !== 'done',
                    'bg-[var(--arcs-bar)] text-white' => ! $navigate && $state === 'done',
                    'text-white' => ! $navigate && $state === 'now',
                    'bg-white text-gray-500' => ! $navigate && $state === 'next',
                ])
                @if ($navigate)
                    :class="step > {{ $index }}
                        ? 'bg-[var(--arcs-bar)] text-white'
                        : (step === {{ $index }} ? 'text-white' : 'bg-white text-gray-500')"
                    :style="step === {{ $index }} ? 'background: var(--arcs-accent)' : ''"
                @elseif ($state === 'now')
                    style="background: var(--arcs-accent)"
                @endif
            >
                <span
                    class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold"
                    @if ($navigate)
                        :class="step > {{ $index }} ? 'bg-emerald-500 text-white' : (step === {{ $index }} ? 'bg-white/20' : 'bg-gray-100 text-gray-500')"
                        x-text="step > {{ $index }} ? '✓' : '{{ $index + 1 }}'"
                    @endif
                >
                    @if (! $navigate)
                        @if ($state === 'done')
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-[11px] text-white">✓</span>
                        @else
                            {{ $index + 1 }}
                        @endif
                    @endif
                </span>
                <span>{{ $label }}</span>
            </button>
            @if (! $loop->last)
                <span class="hidden h-px w-6 bg-gray-200 sm:block" aria-hidden="true"></span>
            @endif
        </li>
    @endforeach
</ol>
