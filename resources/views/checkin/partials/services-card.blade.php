{{-- Special services panel on the check-in screen (C7: SR-2, SR-44). Expects $passenger. --}}
@php
    $serviceErrors = $errors->getBag('addService');
    $servicesEditable = $passenger->checkinDetailsEditable();
    $selectedCode = $serviceErrors->any() ? old('code') : null;
    $activeCodes = $passenger->specialServices->map(fn ($s) => $s->type->value)->all();
@endphp

<x-panel>
    <h2 class="text-base font-semibold text-gray-900">Special services</h2>

    @if ($passenger->specialServices->isEmpty())
        <p class="mt-2 text-sm text-gray-600">No special services.</p>
    @else
        <ul class="mt-3 divide-y text-sm" style="border-color: var(--arcs-border);">
            @foreach ($passenger->specialServices as $service)
                <li class="flex items-start justify-between gap-3 py-2" style="border-color: var(--arcs-border);">
                    <div>
                        <span class="arcs-mono font-semibold" style="color: var(--arcs-accent-text);">{{ $service->type->value }}</span>
                        <span class="ml-1 text-gray-700">{{ $service->type->label() }}</span>
                        @if ($service->details)
                            <span class="block text-gray-600">{{ $service->details }}</span>
                        @endif
                    </div>
                    @if ($servicesEditable)
                        <form method="POST" action="{{ route('checkin.services.destroy', $service) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-medium hover:underline" style="color: var(--arcs-error);">Remove</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($servicesEditable)
        <form method="POST" action="{{ route('checkin.services.store', $passenger) }}" class="mt-6 space-y-4 border-t pt-4" style="border-color: var(--arcs-border);"
            x-data="{ code: @js($selectedCode) }">
            @csrf

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (\App\Enums\SpecialServiceCode::cases() as $code)
                    @php
                        $already = in_array($code->value, $activeCodes, true);
                    @endphp
                    <label
                        class="relative flex cursor-pointer gap-3 rounded-[14px] border bg-white p-3 transition {{ $already ? 'opacity-50' : '' }}"
                        :style="code === '{{ $code->value }}' ? 'border-color: var(--arcs-accent); box-shadow: inset 0 0 0 1px var(--arcs-accent);' : 'border-color: var(--arcs-border);'"
                    >
                        <input
                            type="radio"
                            name="code"
                            value="{{ $code->value }}"
                            class="sr-only"
                            @change="code = '{{ $code->value }}'"
                            @checked($selectedCode === $code->value)
                            @disabled($already)
                            required
                        />
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-xs font-bold arcs-mono" style="background: #EEF0F4; color: var(--arcs-accent-text);" aria-hidden="true">{{ $code->value }}</span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900">
                                <span class="arcs-mono" style="color: var(--arcs-accent-text);">{{ $code->value }}</span>
                            </span>
                            <span class="mt-0.5 block text-xs text-gray-600">{{ $code->label() }}{{ $code->requiresDetails() ? ' · details required' : '' }}</span>
                            @if ($already)
                                <span class="mt-1 block text-xs font-medium text-gray-500">Already on booking</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
            <x-input-error :messages="$serviceErrors->get('code')" class="mt-2" />

            <div>
                <x-input-label for="service-details" value="Details" />
                <x-text-input id="service-details" name="details" type="text" maxlength="500" class="mt-1 block w-full"
                    :value="$serviceErrors->any() ? old('details') : ''" placeholder="e.g. Cat, 6 kg with carrier" />
                <x-input-error :messages="$serviceErrors->get('details')" class="mt-2" />
            </div>

            <div>
                <button type="submit" class="arcs-btn-primary">Add service</button>
            </div>
        </form>
    @endif
</x-panel>
