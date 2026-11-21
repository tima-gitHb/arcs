<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'ARCS') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $user = Auth::user();
    $role = $user->role;
    $stationCode = $user->airport_code;
    $initials = collect(preg_split('/\s+/', trim($user->name)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $menuGroups = match ($role) {
        App\Enums\Role::Manager => [],
        App\Enums\Role::Reservation => [
            'Bookings' => ['New reservation', 'Find reservation'],
        ],
        App\Enums\Role::Checkin => [
            'Check-in' => ['Check in passenger', 'Check-in lists'],
        ],
        App\Enums\Role::Gate => [
            'Gate' => ['Manifest'],
        ],
    };
    $menu = $role->menu();
    $showTodaysInMenu = in_array($role, [App\Enums\Role::Reservation, App\Enums\Role::Checkin, App\Enums\Role::Gate], true);
    $managerStation = $role === App\Enums\Role::Manager
        ? \App\Support\ManagerStation::apply(request())
        : null;
    $managerAirports = $role === App\Enums\Role::Manager
        ? \App\Support\ManagerStation::airports(request())
        : collect();
    $managerStationAirport = is_string($managerStation) && $managerStation !== ''
        ? $managerAirports->firstWhere('code', $managerStation)
        : null;
@endphp
<body class="font-sans antialiased text-gray-900" style="background: var(--arcs-page);">
<div data-arcs-shell class="arcs-shell flex min-h-screen flex-col lg:flex-row">
    <aside class="flex w-full shrink-0 flex-col text-white lg:w-[264px]" style="background: var(--arcs-side);" aria-label="Role menu">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div>
                <a href="{{ route($role->homeRoute()) }}" class="flex items-center gap-2.5">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl text-sm font-bold" style="background: var(--arcs-accent);" aria-hidden="true">✈</span>
                    <span>
                        <span class="block text-base font-bold tracking-wide">ARCS</span>
                        <span class="block text-xs text-white/60" data-role-badge>{{ $role->label() }}</span>
                    </span>
                </a>
            </div>

            @if ($role === App\Enums\Role::Manager)
                <div class="rounded-2xl px-3 py-2.5" style="background: #353B4F;">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                @if ($managerStationAirport)
                                    {{ $managerStationAirport->code }} · {{ $managerStationAirport->city }}
                                @else
                                    All stations
                                @endif
                            </p>
                            <p class="mt-0.5 text-xs text-white/60">
                                @if ($managerStationAirport)
                                    <x-local-time :at="now()" :airport="$managerStationAirport->code" format="H:i" /> local
                                @elseif ($stationCode)
                                    <x-station-time :at="now()" format="H:i" /> local
                                @else
                                    <x-station-time :at="now()" format="H:i" /> UTC
                                @endif
                            </p>
                        </div>
                    </div>
                    <form method="GET" action="{{ request()->url() }}" class="no-print mt-2">
                        @foreach (request()->except('station') as $name => $value)
                            @if (is_scalar($value))
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <label for="manager-station" class="sr-only">Station</label>
                        <select id="manager-station" name="station" onchange="this.form.submit()" class="h-9 w-full rounded-lg border-0 bg-white/10 px-2 text-sm text-white">
                            <option value="" @selected($managerStation === null || $managerStation === '')>All stations</option>
                            @foreach ($managerAirports as $airport)
                                <option value="{{ $airport->code }}" @selected($managerStation === $airport->code) class="text-gray-900">{{ $airport->code }} · {{ $airport->city }}</option>
                            @endforeach
                        </select>
                    </form>
                    @if (is_string($managerStation) && $managerStation !== '')
                        <p class="no-print mt-2 text-xs text-white/80">
                            Station: {{ $managerStation }} ·
                            <a href="{{ request()->fullUrlWithQuery(['station' => '']) }}" class="font-medium text-white underline">Show all stations</a>
                        </p>
                    @endif
                </div>
            @elseif ($stationCode)
                @php
                    $staffStation = $user->relationLoaded('airport')
                        ? $user->airport
                        : $user->airport()->first();
                @endphp
                <div class="rounded-2xl px-3 py-2.5" style="background: var(--arcs-side-active);">
                    <p class="text-sm font-semibold">
                        {{ $stationCode }}@if ($staffStation) · {{ $staffStation->city }}@endif
                    </p>
                    <p class="mt-0.5 text-xs text-white/60">
                        <x-local-time :at="now()" :airport="$stationCode" format="H:i" /> local
                    </p>
                </div>
            @else
                <div class="rounded-2xl px-3 py-2.5 text-xs text-white/60" style="background: var(--arcs-side-active);">
                    <x-station-time :at="now()" format="H:i" /> UTC
                </div>
            @endif

            @if ($role === App\Enums\Role::Manager)
                <x-manager-nav />
            @else
                <nav class="space-y-4">
                    @foreach ($menuGroups as $group => $labels)
                        <div>
                            <p class="mb-1.5 px-2 text-[10px] font-semibold uppercase tracking-wider text-white/35">{{ $group }}</p>
                            <ul class="space-y-1 text-sm">
                                @foreach ($labels as $label)
                                    @php $routeName = $menu[$label] ?? null; @endphp
                                    @if ($routeName)
                                        @php
                                            $menuActive = match ($label) {
                                                'Check-in lists' => request()->routeIs('checkin.flights.*'),
                                                'Check in passenger' => request()->routeIs('checkin.home')
                                                    || request()->routeIs('checkin.passengers.*')
                                                    || request()->routeIs('checkin.reservations.*'),
                                                default => request()->routeIs($routeName),
                                            };
                                        @endphp
                                        <li>
                                            @if (Route::has($routeName))
                                                @php
                                                    $menuHref = $label === 'Check-in lists'
                                                        ? route('checkin.home').'#checking-in'
                                                        : route($routeName);
                                                    $menuClass = 'flex min-h-[44px] items-center rounded-full px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40 '.($menuActive ? 'font-medium' : 'text-white/75 hover:bg-white/5');
                                                    $menuStyle = $menuActive ? 'background: #353B4F' : null;
                                                @endphp
                                                <a href="{{ $menuHref }}" class="{{ $menuClass }}" style="{{ $menuStyle }}" {!! $menuActive ? 'aria-current="page"' : '' !!}>
                                                    {{ $label }}
                                                </a>
                                            @else
                                                <span class="flex min-h-[44px] items-center rounded-full px-3 py-2 text-white/35">{{ $label }}</span>
                                            @endif
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </nav>
            @endif

            @if ($showTodaysInMenu)
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <x-todays-flights :role="$role->value" compact />
                </div>
            @else
                <div class="flex-1"></div>
            @endif

            <div class="mt-auto rounded-2xl p-3" style="background: #353B4F;" data-user-card>
                <div class="mb-3 flex items-center gap-2.5">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold" style="background: var(--arcs-accent);">{{ $initials }}</span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-white/55">{{ $role->label() }}</span>
                    </span>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('profile.edit') }}" class="inline-flex min-h-[36px] flex-1 items-center justify-center rounded-full border border-white/25 px-3 text-xs font-semibold text-white hover:bg-white/10">Profile</a>
                    <form method="POST" action="{{ route('logout') }}" class="flex-1">
                        @csrf
                        <button type="submit" class="inline-flex min-h-[36px] w-full items-center justify-center rounded-full bg-white px-3 text-xs font-semibold text-gray-900">
                            Log out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <x-flash />

        <div class="flex flex-1 flex-col xl:flex-row">
            <main class="min-w-0 flex-1 p-4 sm:p-6 lg:p-8">
                @isset($search)
                    <div class="mb-4">{{ $search }}</div>
                @endisset
                @isset($header)
                    <div class="mb-6">{{ $header }}</div>
                @endisset
                {{ $slot }}
            </main>

            @isset($aside)
                <aside id="arcs-aside" class="w-full shrink-0 p-4 xl:w-[32rem] xl:pl-0 xl:pr-6 xl:pt-6" style="background: var(--arcs-page);">
                    {{ $aside }}
                </aside>
            @endisset
        </div>
    </div>
</div>
</body>
</html>
