{{--
    Add / edit form for a travel document.
    Expects: $action, $method (POST|PUT), $bag (error bag), $document (or null), $submit (button text).
--}}
@php
    $errorsHere = $errors->getBag($bag);
    // Old input belongs to the form that was just submitted, not to both forms.
    $value = fn (string $field, $current) => $errorsHere->any() ? old($field, $current) : $current;
@endphp

<form method="POST" action="{{ $action }}" class="mt-3 grid gap-4 sm:grid-cols-2">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div>
        <x-input-label :for="$bag.'-type'" value="Type" />
        <select id="{{ $bag }}-type" name="type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-navy focus:ring-navy" required>
            @unless ($document)
                <option value="">Choose a type…</option>
            @endunless
            @foreach (\App\Enums\DocumentType::cases() as $type)
                <option value="{{ $type->value }}" @selected($value('type', $document?->type->value) === $type->value)>
                    {{ $type->label() }}
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errorsHere->get('type')" class="mt-2" />
    </div>

    <div>
        <x-input-label :for="$bag.'-number'" value="Number" />
        <x-text-input :id="$bag.'-number'" name="number" type="text" class="mt-1 block w-full font-mono uppercase"
            :value="$value('number', $document?->number)" maxlength="30" required autocomplete="off" />
        <x-input-error :messages="$errorsHere->get('number')" class="mt-2" />
    </div>

    <div>
        <x-input-label :for="$bag.'-issuing_country'" value="Issuing country" />
        <x-country-select :id="$bag.'-issuing_country'" name="issuing_country"
            :selected="$value('issuing_country', $document?->issuing_country)" />
        <x-input-error :messages="$errorsHere->get('issuing_country')" class="mt-2" />
    </div>

    <div>
        <x-input-label :for="$bag.'-expiry_date'" value="Expiry date" />
        <x-text-input :id="$bag.'-expiry_date'" name="expiry_date" type="date" class="mt-1 block"
            :value="$value('expiry_date', $document?->expiry_date?->toDateString())" required />
        <x-input-error :messages="$errorsHere->get('expiry_date')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <button type="submit" class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">
            {{ $submit }}
        </button>
    </div>
</form>
