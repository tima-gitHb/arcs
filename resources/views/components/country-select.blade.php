{{--
    Searchable country list (SR-49): staff pick a name, the form sends the ISO-2 code.
    <x-country-select id="..." name="issuing_country" :selected="$code" />
--}}
@props(['id', 'name', 'selected' => null])

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
