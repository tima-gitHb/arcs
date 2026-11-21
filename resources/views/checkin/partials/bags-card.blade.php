{{-- Bags card on the check-in screen (C4 / C9 / C10: SR-9, SR-43, SR-58, SR-59). Expects $passenger. --}}
@php
    $bagErrors = $errors->getBag('addBag');
    $canRemove = $passenger->status === \App\Enums\PassengerStatus::Booked;
    $allowance = app(\App\Services\AllowanceService::class)->for($passenger);
    $excess = app(\App\Services\BagService::class)->excess($passenger);
    $usedKgLabel = rtrim(rtrim(number_format($excess->kgUsed, 1, '.', ''), '0'), '.');
    $excessKgLabel = rtrim(rtrim(number_format($excess->excessKg, 1, '.', ''), '0'), '.');
@endphp

<x-panel>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-gray-900">Bags</h2>
        @if ($passenger->bags->isNotEmpty())
            <a href="{{ route('checkin.passengers.bag-tags', $passenger) }}" target="_blank"
               class="text-sm font-medium hover:underline" style="color: var(--arcs-accent-text);">Print all tags</a>
        @endif
    </div>

    @if ($allowance->isSet)
        <p class="mt-2 text-sm" style="color: var(--arcs-accent-text);">
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
            Used: {{ $excess->piecesUsed }} {{ $excess->piecesUsed === 1 ? 'piece' : 'pieces' }} / {{ $usedKgLabel }} kg
        </p>
        @if ($excess->hasExcess())
            @php
                $bagsService = app(\App\Services\BagService::class);
                $dueCents = $bagsService->excessDueCents($passenger);
                $paidCents = $bagsService->excessPaidCents($passenger);
                $receiptPayment = session('excess_payment_id')
                    ? \App\Models\Payment::query()->find(session('excess_payment_id'))
                    : \App\Models\Payment::query()
                        ->where('passenger_id', $passenger->id)
                        ->where('kind', \App\Enums\PaymentKind::ExcessBaggage)
                        ->where('status', \App\Enums\PaymentStatus::Succeeded)
                        ->latest('id')
                        ->first();
            @endphp
            <p class="mt-1 text-sm font-medium" style="color: var(--arcs-accent-text);">
                Excess baggage:
                @if ($excess->extraBags > 0)
                    {{ $excess->extraBags }} extra bag{{ $excess->extraBags === 1 ? '' : 's' }}
                @endif
                @if ($excess->extraBags > 0 && $excess->excessKg > 0) · @endif
                @if ($excess->excessKg > 0)
                    {{ $excessKgLabel }} kg excess
                @endif
                —
                @if ($excess->priceIsSet())
                    {{ \App\Support\Money::format($excess->quote->totalCents) }}
                    @if ($paidCents > 0)
                        <span class="text-gray-600">({{ \App\Support\Money::format($paidCents) }} paid)</span>
                    @endif
                @else
                    Price not set
                @endif
            </p>

            @if ($dueCents > 0 && $excess->priceIsSet() && $passenger->checkinDetailsEditable())
                <div class="mt-4 border-t pt-4" style="border-color: var(--arcs-border);" x-show="step === 4" x-cloak>
                    <p class="mb-3 text-sm font-medium" style="color: var(--arcs-accent-text);">Collect payment · {{ \App\Support\Money::format($dueCents) }}</p>
                    <form method="POST" action="{{ route('checkin.excess.pay', $passenger) }}" autocomplete="off">
                        @csrf
                        <x-card-form :amount-cents="$dueCents" title="Collect payment" />
                        <button type="submit" class="arcs-btn-primary mt-3">
                            Charge {{ \App\Support\Money::format($dueCents) }}
                        </button>
                    </form>
                </div>
            @elseif ($receiptPayment && $dueCents === 0)
                <div class="mt-3">
                    <x-receipt :payment="$receiptPayment" :lines="$excess->quote?->lines ?? []" />
                </div>
            @endif
        @endif
    @endif

    @if ($passenger->bags->isEmpty())
        <p class="mt-2 text-sm text-gray-600">No bags.</p>
    @else
        <div class="arcs-table-wrap mt-3">
            <table class="arcs-table">
                <thead>
                    <tr>
                        <th>Tag</th>
                        <th>Weight</th>
                        <th>Type</th>
                        <th>Handling</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($passenger->bags as $bag)
                        <tr>
                            <td class="arcs-mono">{{ $bag->tag_no }}</td>
                            <td>{{ $bag->weight_kg }} kg</td>
                            <td>
                                {{ $bag->type->label() }}
                                @if (! $bag->type->countsTowardAllowance())
                                    <span class="ml-1 text-xs text-gray-500">(free)</span>
                                @endif
                            </td>
                            <td>{{ $bag->handling->label() }}</td>
                            <td class="text-right">
                                <div class="flex flex-wrap items-center justify-end gap-3">
                                    <a href="{{ route('checkin.bags.tag', $bag) }}" target="_blank"
                                       class="text-xs font-medium hover:underline" style="color: var(--arcs-accent-text);">Print tag</a>
                                    <form method="POST" action="{{ route('checkin.bags.tag.reprint', $bag) }}" target="_blank">
                                        @csrf
                                        <button type="submit" class="text-xs font-medium hover:underline" style="color: var(--arcs-accent-text);">Reprint</button>
                                    </form>
                                    @if ($canRemove)
                                        <form method="POST" action="{{ route('checkin.bags.destroy', $bag) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-medium hover:underline" style="color: var(--arcs-error);">Remove</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($passenger->checkinDetailsEditable())
        <form method="POST" action="{{ route('checkin.bags.store', $passenger) }}" class="mt-6 flex flex-wrap items-end gap-4 border-t pt-4" style="border-color: var(--arcs-border);" x-show="step === 2" x-cloak>
            @csrf
            <div>
                <x-input-label for="bag-weight" value="Weight (kg)" />
                <x-text-input id="bag-weight" name="weight_kg" type="number" step="0.1" min="0.1" max="99.9" class="mt-1 block w-28"
                    :value="$bagErrors->any() ? old('weight_kg') : ''" required />
                <x-input-error :messages="$bagErrors->get('weight_kg')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="bag-type" value="Type" />
                <select id="bag-type" name="type" class="arcs-input mt-1 w-auto">
                    @foreach (\App\Enums\BagType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(($bagErrors->any() ? old('type') : 'Standard') === $type->value)>
                            {{ $type->label() }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$bagErrors->get('type')" class="mt-2" />
            </div>
            <button type="submit" class="arcs-btn-primary">Add bag</button>
        </form>
        <p class="mt-2 text-xs text-gray-500" x-show="step === 2">Excess is pooled by weight and pieces. Wheelchairs and strollers are free. Payment of excess is a separate step.</p>
    @endif
</x-panel>
