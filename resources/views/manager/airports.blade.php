<x-app-layout>
    <x-page-header title="Airports" subtitle="Airports of the network. Open a row to edit details; the code never changes.">
        <x-slot:breadcrumb>
            <span>Airports</span>
        </x-slot:breadcrumb>
        <a href="{{ route('manager.airports.create') }}" class="arcs-btn-primary">+ New airport</a>
    </x-page-header>

    <div class="space-y-6">
        <x-panel>
            <form method="GET" action="{{ route('manager.airports') }}" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[12rem] flex-1">
                    <x-input-label for="q" value="Search" />
                    <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" :value="$filters['q']" placeholder="Code, name or city" />
                </div>
                <div>
                    <x-input-label for="country" value="Country" />
                    <select id="country" name="country" class="arcs-input mt-1 block w-56">
                        <option value="">All countries</option>
                        @foreach ($countries as $code => $name)
                            <option value="{{ $code }}" @selected($filters['country'] === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-primary-button>Filter</x-primary-button>
            </form>
        </x-panel>

        <x-panel>
            <div class="arcs-table-wrap -mx-5 sm:-mx-6">
                <table class="arcs-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>City</th>
                            <th>Country</th>
                            <th>Time zone</th>
                            <th>Min. connection</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($airports as $airport)
                            <tr>
                                <td class="arcs-mono font-semibold">{{ $airport->code }}</td>
                                <td>{{ $airport->name }}</td>
                                <td>{{ $airport->city }}</td>
                                <td>{{ app(\App\Services\CountryList::class)->name($airport->country) }}</td>
                                <td class="text-sm">{{ $airport->timezone }}</td>
                                <td>{{ $airport->min_connection_minutes }} min</td>
                                <td class="text-right">
                                    <a href="{{ route('manager.airports.edit', $airport) }}" class="font-medium hover:underline" style="color: var(--arcs-accent-text);">Edit →</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7"><x-empty-state message="No airports match." /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>
    </div>
</x-app-layout>
