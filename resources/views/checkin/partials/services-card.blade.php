{{-- Special services panel on the check-in screen (C7: SR-2, SR-44). Expects $passenger. --}}
@php
    $serviceErrors = $errors->getBag('addService');
    $servicesEditable = $passenger->checkinDetailsEditable();
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
        <form method="POST" action="{{ route('checkin.services.store', $passenger) }}" class="mt-6 grid gap-4 border-t pt-4 sm:grid-cols-2" style="border-color: var(--arcs-border);">
            @csrf
            <div>
                <x-input-label for="service-code" value="Service" />
                <select id="service-code" name="code" class="arcs-input mt-1">
                    @foreach (\App\Enums\SpecialServiceCode::cases() as $code)
                        <option value="{{ $code->value }}" @selected($serviceErrors->any() && old('code') === $code->value)>
                            {{ $code->value }} – {{ $code->label() }}{{ $code->requiresDetails() ? ' (details required)' : '' }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$serviceErrors->get('code')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="service-details" value="Details" />
                <x-text-input id="service-details" name="details" type="text" maxlength="500" class="mt-1 block w-full"
                    :value="$serviceErrors->any() ? old('details') : ''" placeholder="e.g. Cat, 6 kg with carrier" />
                <x-input-error :messages="$serviceErrors->get('details')" class="mt-2" />
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="arcs-btn-primary">Add service</button>
            </div>
        </form>
    @endif
</x-panel>
