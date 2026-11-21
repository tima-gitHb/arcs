{{--
    Searchable country list (SR-49): staff pick a name, the form sends the ISO-2 code.
    <x-country-select id="..." name="issuing_country" :selected="$code" />
    simple: single-row select (manager airport forms); default keeps the open listbox.
--}}
@props(['id', 'name', 'selected' => null, 'simple' => false])

@if ($simple)
    <div x-data="{ search: '' }" class="mt-1 space-y-2">
        <input type="search" x-model="search" @keydown.enter.prevent placeholder="Search country…" autocomplete="off"
            aria-label="Search country" class="arcs-input block w-full text-sm">
        <select id="{{ $id }}" name="{{ $name }}" required class="arcs-input block w-full text-sm">
            <option value="" disabled @selected($selected === null || $selected === '')>Choose a country</option>
            @foreach (app(\App\Services\CountryList::class)->all() as $code => $country)
                <option value="{{ $code }}" @selected($selected === $code) data-name="{{ mb_strtolower($country) }}"
                    x-show="$el.dataset.name.includes(search.toLowerCase())"
                    x-bind:disabled="search !== '' && ! $el.dataset.name.includes(search.toLowerCase())">{{ $country }}</option>
            @endforeach
        </select>
    </div>
@else
    <div x-data="{ search: '' }" class="mt-1">
        <input type="search" x-model="search" @keydown.enter.prevent placeholder="Search country…" autocomplete="off"
            aria-label="Search country" class="block w-full rounded-t-md border-gray-300 text-sm shadow-sm focus:border-navy focus:ring-navy">
        <select id="{{ $id }}" name="{{ $name }}" size="5" required
            class="block w-full rounded-b-md border-t-0 border-gray-300 text-sm shadow-sm focus:border-navy focus:ring-navy">
            @foreach (app(\App\Services\CountryList::class)->all() as $code => $country)
                <option value="{{ $code }}" @selected($selected === $code) data-name="{{ mb_strtolower($country) }}"
                    x-show="$el.dataset.name.includes(search.toLowerCase())">{{ $country }}</option>
            @endforeach
        </select>
    </div>
@endif
