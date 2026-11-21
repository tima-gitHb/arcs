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
    $menuGroups = match ($role) {
        App\Enums\Role::Manager => [
            'Operations' => ['Flight lists', 'Dashboard', 'Flights and aircraft', 'Airports'],
            'People' => ['Staff accounts'],
            'Pricing' => ['Rules and allowances', 'Fares and fees'],
            'Reports' => ['Sales per flight', 'Payments', 'Audit log'],
        ],
        App\Enums\Role::Reservation => [
            'Bookings' => ['New reservation', 'Find reservation'],
        ],
        App\Enums\Role::Checkin => [
            'Check-in' => ['Check in passenger'],
        ],
        App\Enums\Role::Gate => [
            'Gate' => ['Manifest'],
        ],
    };
    $menu = $role->menu();
@endphp
<body class="font-sans antialiased text-gray-900" style="background: var(--arcs-page);">
<div data-arcs-shell class="arcs-shell flex min-h-screen flex-col">
    <header class="flex flex-wrap items-center gap-3 px-4 py-3 text-white sm:px-6" style="background: var(--arcs-bar);">
        <a href="{{ route($role->homeRoute()) }}" class="text-lg font-semibold tracking-wide">ARCS</a>
        {{-- Role::label() kept for B4/B5/UI1; accent fill matches docs/ui mocks. --}}
        <span class="rounded-md px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white" style="background: var(--arcs-accent);" data-role-badge>
            {{ $role->label() }}
        </span>
        @if ($role === App\Enums\Role::Manager && trim(strip_tags((string) ($station ?? ''))) !== '')
            <div class="flex items-center">{{ $station }}</div>
        @elseif ($stationCode)
            {{-- Station code only (no airport row query) so page query budgets stay flat. --}}
            <span class="text-xs text-white/70">
                {{ $stationCode }} · <x-station-time :at="now()" format="H:i" /> local
            </span>
        @else
            <span class="text-xs text-white/70"><x-station-time :at="now()" format="H:i" /> UTC</span>
        @endif

        <div class="min-w-[8rem] flex-1">
            @isset($search)
                {{ $search }}
            @elseif (in_array($role, [App\Enums\Role::Reservation, App\Enums\Role::Checkin], true))
                <form method="GET" action="{{ $role === App\Enums\Role::Reservation ? route('reservation.search') : route('checkin.home') }}" class="max-w-xs">
                    <label for="shell-q" class="sr-only">PNR or last name</label>
                    <input id="shell-q" name="q" type="search" placeholder="PNR or last name"
                           value="{{ request('q') }}"
                           class="w-full rounded-lg border-0 bg-white/10 px-3 py-2 text-sm text-white placeholder:text-white/50 focus:bg-white/15 focus:outline-none focus:ring-1 focus:ring-white/40" />
                </form>
            @endif
        </div>

        <div class="ml-auto flex items-center gap-3 text-sm">
            <span class="hidden sm:inline">{{ $user->name }}</span>
            <a href="{{ route('profile.edit') }}" class="text-white/80 hover:text-white hover:underline">Profile</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="inline-flex min-h-[36px] items-center rounded-lg border border-white/40 bg-transparent px-3 py-1 text-xs font-medium text-white hover:bg-white/10">
                    Log out
                </button>
            </form>
        </div>
    </header>

    <x-flash />

    <div class="flex flex-1 flex-col md:flex-row">
        <nav class="w-full shrink-0 p-3 text-white md:w-56" style="background: var(--arcs-side);" aria-label="Role menu">
            @foreach ($menuGroups as $group => $labels)
                <p class="mb-1 mt-3 px-3 text-[11px] font-semibold uppercase tracking-wider text-white/40 first:mt-0">{{ $group }}</p>
                <ul class="mb-2 space-y-0.5 text-sm">
                    @foreach ($labels as $label)
                        @php $routeName = $menu[$label] ?? null; @endphp
                        @if ($routeName)
                            <li>
                                @if (Route::has($routeName))
                                    <a href="{{ route($routeName) }}"
                                       @class([
                                           'block rounded-lg px-3 py-2.5 min-h-[44px] flex items-center',
                                           'font-medium' => request()->routeIs($routeName),
                                           'text-white/80 hover:bg-white/5' => ! request()->routeIs($routeName),
                                       ])
                                       @style(['background: var(--arcs-side-active)' => request()->routeIs($routeName)])
                                       @if (request()->routeIs($routeName)) aria-current="page" @endif>
                                        {{ $label }}
                                    </a>
                                @else
                                    <span class="block rounded-lg px-3 py-2.5 text-white/35">{{ $label }}</span>
                                @endif
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endforeach
        </nav>

        <main class="min-w-0 flex-1 p-4 sm:p-6">
            @isset($header)
                <div class="mb-6">{{ $header }}</div>
            @endisset
            {{ $slot }}
        </main>

        @isset($aside)
            <aside id="arcs-aside" class="w-full shrink-0 border-t p-4 md:w-72 md:border-l md:border-t-0 lg:w-80" style="border-color: var(--arcs-border); background: var(--arcs-page);">
                {{ $aside }}
            </aside>
        @endisset
    </div>
</div>
</body>
</html>
