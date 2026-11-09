{{--
    Shared card capture (R9 / C11 / upgrade). Number and CVC are never old()'d or flashed.
    Field names stay card_number, card_expiry, card_cvc, card_name so controllers can exclude them.
    <x-card-form :amount-cents="$cents" /> or :amount-label="'$50.00'"
    Optional id-prefix when several forms appear on one page.
--}}
@props([
    'amountCents' => null,
    'amountLabel' => null,
    'idPrefix' => '',
    'title' => 'Card (read from caller)',
    'hint' => 'Card only · test gateway · number and CVC are never stored',
])

@php
    $prefix = $idPrefix !== '' ? $idPrefix.'-' : '';
    $amountText = $amountLabel
        ?? ($amountCents !== null ? \App\Support\Money::format((int) $amountCents) : null);
@endphp

<div {{ $attributes->merge(['class' => 'rounded-lg bg-white p-6 shadow-sm']) }} data-card-form>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-navy">{{ $title }}</h2>
            <p class="mt-1 text-sm text-gray-600">{{ $hint }}</p>
        </div>
        <span id="{{ $prefix }}card-brand" class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-gray-600">Card</span>
    </div>

    @if ($amountText !== null)
        <p class="mt-3 text-sm text-gray-700">
            Amount to charge:
            <span id="{{ $prefix }}card-form-amount" class="font-semibold text-navy">{{ $amountText }}</span>
        </p>
    @endif

    <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <x-input-label :for="$prefix.'card_number'" value="Card number" />
            <x-text-input :id="$prefix.'card_number'" name="card_number" type="text" class="mt-1 block w-full font-mono tracking-wider"
                inputmode="numeric" autocomplete="off" required maxlength="19" placeholder="4242 4242 4242 4242" />
            <p class="mt-1 text-xs text-gray-500">Test approve: 4242…4242 · decline: 4000…0002 · expiry e.g. 12/30 · any CVC</p>
            <x-input-error :messages="$errors->get('card_number')" class="mt-2" />
        </div>
        <div>
            <x-input-label :for="$prefix.'card_expiry'" value="Expiry" />
            <x-text-input :id="$prefix.'card_expiry'" name="card_expiry" type="text" class="mt-1 block w-full font-mono"
                :value="old('card_expiry')" placeholder="12/30" autocomplete="off" required maxlength="5" />
            <p class="mt-1 text-xs text-gray-500">MM/YY · this month or later</p>
            <x-input-error :messages="$errors->get('card_expiry')" class="mt-2" />
        </div>
        <div>
            <x-input-label :for="$prefix.'card_cvc'" value="CVC" />
            <x-text-input :id="$prefix.'card_cvc'" name="card_cvc" type="password" class="mt-1 block w-28 font-mono"
                autocomplete="off" required maxlength="4" placeholder="123" />
            <x-input-error :messages="$errors->get('card_cvc')" class="mt-2" />
        </div>
        <div class="sm:col-span-2">
            <x-input-label :for="$prefix.'card_name'" value="Name on card" />
            <x-text-input :id="$prefix.'card_name'" name="card_name" type="text" class="mt-1 block w-full"
                autocomplete="off" maxlength="80" placeholder="As printed on the card" />
            <x-input-error :messages="$errors->get('card_name')" class="mt-2" />
        </div>
    </div>
</div>

<script type="module">
    (() => {
        const number = document.getElementById(@json($prefix.'card_number'));
        const expiry = document.getElementById(@json($prefix.'card_expiry'));
        const brand = document.getElementById(@json($prefix.'card-brand'));
        if (!number || !expiry) return;

        number.addEventListener('input', () => {
            const digits = number.value.replace(/\D/g, '').slice(0, 16);
            number.value = digits.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
            const first = digits[0];
            if (brand) {
                brand.textContent = first === '4' ? 'Visa' : first === '5' ? 'Mastercard' : 'Card';
            }
        });

        expiry.addEventListener('input', () => {
            let value = expiry.value.replace(/\D/g, '').slice(0, 4);
            if (value.length >= 3) {
                value = `${value.slice(0, 2)}/${value.slice(2)}`;
            }
            expiry.value = value;
        });
    })();
</script>
