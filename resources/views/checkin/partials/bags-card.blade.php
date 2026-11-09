{{-- Bags card on the check-in screen (C4 / C9: SR-9, SR-43, SR-58). Expects $passenger. --}}
@php
    $bagErrors = $errors->getBag('addBag');
    $canRemove = $passenger->status === \App\Enums\PassengerStatus::Booked;
    $allowance = app(\App\Services\AllowanceService::class)->for($passenger);
    $countedBags = $passenger->bags
        ->filter(fn ($bag) => $bag->offloaded_at === null && $bag->type->countsTowardAllowance());
    $piecesUsed = $countedBags->count();
    $kgUsed = (float) $countedBags->sum(fn ($bag) => (float) $bag->weight_kg);
    $usedKgLabel = rtrim(rtrim(number_format($kgUsed, 1, '.', ''), '0'), '.');
@endphp

<div class="rounded-lg bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-navy">Bags</h2>

    @if ($allowance->isSet)
        <p class="mt-2 text-sm text-navy">
            Allowance: {{ $allowance->cabinClass->label() }}
            {{ $allowance->pieces }} × {{ rtrim(rtrim(number_format($allowance->kgPerBag, 1, '.', ''), '0'), '.') }} kg
            ({{ rtrim(rtrim(number_format($allowance->totalKg, 1, '.', ''), '0'), '.') }} kg total)
            @if ($passenger->extra_bags > 0 || $passenger->extra_kg > 0)
                <span class="text-gray-600">
                    — includes
                    @if ($passenger->extra_bags > 0)
                        {{ $passenger->extra_bags }} extra bag{{ $passenger->extra_bags === 1 ? '' : 's' }}
                    @endif
                    @if ($passenger->extra_bags > 0 && $passenger->extra_kg > 0) and @endif
                    @if ($passenger->extra_kg > 0)
                        {{ $passenger->extra_kg }} extra kg
                    @endif
                    paid at booking
                </span>
            @endif
        </p>
        <p class="mt-1 text-sm text-gray-700">
            Used: {{ $piecesUsed }} {{ $piecesUsed === 1 ? 'piece' : 'pieces' }} / {{ $usedKgLabel }} kg
        </p>
    @endif

    @if ($passenger->bags->isEmpty())
        <p class="mt-2 text-sm text-gray-600">No bags.</p>
    @else
        <table class="mt-3 w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="py-1 font-medium">Tag</th>
                    <th class="py-1 font-medium">Weight</th>
                    <th class="py-1 font-medium">Type</th>
                    <th class="py-1 font-medium">Handling</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($passenger->bags as $bag)
                    <tr class="border-t border-gray-100">
                        <td class="py-2 font-mono">{{ $bag->tag_no }}</td>
                        <td class="py-2">{{ $bag->weight_kg }} kg</td>
                        <td class="py-2">
                            {{ $bag->type->label() }}
                            @if ($bag->over_allowance)
                                <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Over allowance</span>
                            @elseif (! $bag->type->countsTowardAllowance())
                                <span class="ml-1 text-xs text-gray-500">(free)</span>
                            @endif
                        </td>
                        <td class="py-2">{{ $bag->handling->label() }}</td>
                        <td class="py-2 text-right">
                            @if ($canRemove)
                                <form method="POST" action="{{ route('checkin.bags.destroy', $bag) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-medium text-red-700 hover:underline">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($passenger->checkinDetailsEditable())
        <form method="POST" action="{{ route('checkin.bags.store', $passenger) }}" class="mt-6 flex flex-wrap items-end gap-4 border-t border-gray-200 pt-4">
            @csrf
            <div>
                <x-input-label for="bag-weight" value="Weight (kg)" />
                <x-text-input id="bag-weight" name="weight_kg" type="number" step="0.1" min="0.1" max="99.9" class="mt-1 block w-28"
                    :value="$bagErrors->any() ? old('weight_kg') : ''" required />
                <x-input-error :messages="$bagErrors->get('weight_kg')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="bag-type" value="Type" />
                <select id="bag-type" name="type" class="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-navy focus:ring-navy">
                    @foreach (\App\Enums\BagType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(($bagErrors->any() ? old('type') : 'Standard') === $type->value)>
                            {{ $type->label() }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$bagErrors->get('type')" class="mt-2" />
            </div>
            <button type="submit" class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">Add bag</button>
        </form>
        <p class="mt-2 text-xs text-gray-500">Extra bags are flagged, never charged. Wheelchairs and strollers are free.</p>
    @endif
</div>
