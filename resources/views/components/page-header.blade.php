@props([
    'title',
    'subtitle' => null,
    'search' => false,
])

@php
    $role = auth()->user()?->role;
    $showSearch = $search || ($role && in_array($role, [App\Enums\Role::Reservation, App\Enums\Role::Checkin], true));
    $searchAction = $role === App\Enums\Role::Reservation
        ? route('reservation.search')
        : ($role === App\Enums\Role::Checkin ? route('checkin.home') : null);
@endphp

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-start justify-between gap-4']) }}>
    <div class="min-w-0">
        @isset($breadcrumb)
            <p class="mb-1 flex flex-wrap items-center gap-1.5 text-xs text-gray-500">{{ $breadcrumb }}</p>
        @endisset
        <h1 class="text-[26px] font-bold tracking-tight text-gray-900">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-gray-500">{{ $subtitle }}</p>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @php
            $actionsHtml = isset($actions) ? trim((string) $actions) : '';
            $slotHtml = trim((string) $slot);
        @endphp
        @if ($actionsHtml !== '')
            {{ $actions }}
        @endif
        @if ($slotHtml !== '')
            {{ $slot }}
        @endif
        @if ($showSearch && $searchAction)
            <form method="GET" action="{{ $searchAction }}" class="w-full sm:w-auto">
                <label for="page-header-q" class="sr-only">Search PNR or last name</label>
                <input
                    id="page-header-q"
                    name="{{ $role === App\Enums\Role::Reservation ? 'pnr' : 'q' }}"
                    type="search"
                    placeholder="Search PNR or last name"
                    value="{{ $role === App\Enums\Role::Reservation ? request('pnr') : request('q') }}"
                    class="min-h-[44px] w-full rounded-full border bg-white px-4 text-sm shadow-sm sm:w-64"
                    style="border-color: var(--arcs-border);"
                />
            </form>
        @endif
    </div>
</div>
