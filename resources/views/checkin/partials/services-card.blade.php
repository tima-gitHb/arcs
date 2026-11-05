{{-- Special services panel on the check-in screen (C7: SR-2, SR-44). Expects $passenger. --}}
@php
    $serviceErrors = $errors->getBag('addService');
    $servicesEditable = $passenger->checkinDetailsEditable();
@endphp

<div class="rounded-lg bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-navy">Special services</h2>

    @if ($passenger->specialServices->isEmpty())
        <p class="mt-2 text-sm text-gray-600">No special services.</p>
    @else
        <ul class="mt-3 divide-y divide-gray-100 text-sm">
            @foreach ($passenger->specialServices as $service)
                <li class="flex items-start justify-between gap-3 py-2">
                    <div>
                        <span class="font-mono font-semibold text-navy">{{ $service->type->value }}</span>
                        <span class="ml-1 text-gray-700">{{ $service->type->label() }}</span>
                        @if ($service->details)
                            <span class="block text-gray-600">{{ $service->details }}</span>
                        @endif
                    </div>
                    @if ($servicesEditable)
                        <form method="POST" action="{{ route('checkin.services.destroy', $service) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-medium text-red-700 hover:underline">Remove</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($servicesEditable)
        <form method="POST" action="{{ route('checkin.services.store', $passenger) }}" class="mt-6 grid gap-4 border-t border-gray-200 pt-4 sm:grid-cols-2">
            @csrf
            <div>
                <x-input-label for="service-code" value="Service" />
                <select id="service-code" name="code" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-navy focus:ring-navy">
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
                <button type="submit" class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">Add service</button>
            </div>
        </form>
    @endif
</div>
