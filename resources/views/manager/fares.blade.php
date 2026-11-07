<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-navy">Fares and fees</h1>
    </x-slot>

    <div class="bg-white rounded-lg shadow-sm p-6 text-gray-700">
        @if (session('status'))
            <div class="mb-6 rounded-md bg-green-50 p-4 text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('manager.fares.update') }}" class="space-y-8">
            @csrf
            @method('PUT')

            <section class="space-y-4">
                <h2 class="text-lg font-semibold text-navy">Fares</h2>
                <p class="text-sm text-gray-600">Ticket price in US dollars, per cabin class and passenger type.</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-gray-500 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-2 font-medium">Cabin class</th>
                                @foreach ($types as $type)
                                    <th class="px-4 py-2 font-medium">{{ $type->label() }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cabins as $cabin)
                                <tr class="border-b border-gray-100 last:border-0 align-top">
                                    <th scope="row" class="px-4 py-3 text-left font-medium text-gray-800">{{ $cabin->label() }}</th>
                                    @foreach ($types as $type)
                                        @php
                                            // fares[economy][adult] in the form, fares.economy.adult in old() and the errors.
                                            $cabinKey = strtolower($cabin->value);
                                            $typeKey = strtolower($type->value);
                                            $field = "fares.$cabinKey.$typeKey";
                                        @endphp
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-1">
                                                <span class="text-gray-600">$</span>
                                                <x-text-input id="fare_{{ $cabinKey }}_{{ $typeKey }}" name="fares[{{ $cabinKey }}][{{ $typeKey }}]" type="number" min="0" max="99999.99" step="0.01" class="w-28"
                                                    :value="old($field, $fares[$cabinKey.'.'.$typeKey] ?? '')" required
                                                    aria-label="{{ $cabin->label() }} {{ $type->label() }} fare in dollars" />
                                            </div>
                                            <x-input-error :messages="$errors->get($field)" class="mt-2" />
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-4">
                <h2 class="text-lg font-semibold text-navy">Fees</h2>
                <p class="text-sm text-gray-600">Price of each extra in US dollars. Excess baggage is charged per kg.</p>

                <div class="grid gap-6 sm:grid-cols-2">
                    @foreach ($feeCodes as $code)
                        <div>
                            <x-input-label for="fee_{{ $code->value }}" :value="$code->label()" />
                            <div class="mt-1 flex items-center gap-1">
                                <span class="text-gray-600">$</span>
                                <x-text-input id="fee_{{ $code->value }}" name="fees[{{ $code->value }}]" type="number" min="0" max="99999.99" step="0.01" class="w-28"
                                    :value="old('fees.'.$code->value, $fees[$code->value] ?? '')" required />
                            </div>
                            <x-input-error :messages="$errors->get('fees.'.$code->value)" class="mt-2" />
                        </div>
                    @endforeach
                </div>
            </section>

            <div>
                <x-primary-button>Save fares and fees</x-primary-button>
            </div>
        </form>
    </div>
</x-app-layout>
