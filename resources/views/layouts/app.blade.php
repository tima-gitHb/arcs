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
    $showTodaysInMenu = in_array($role, [App\Enums\Role::Reservation, App\Enums\Role::Checkin, App\Enums\Role::Gate], true);
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

            @if ($role === App\Enums\Role::Manager && trim(strip_tags((string) ($station ?? ''))) !== '')
                <div class="rounded-2xl px-3 py-2.5 text-xs" style="background: var(--arcs-side-active);">
                    {{ $station }}
                </div>
            @elseif ($stationCode)
                <div class="rounded-2xl px-3 py-2.5" style="background: var(--arcs-side-active);">
                    <p class="text-sm font-semibold">{{ $stationCode }}</p>
                    <p class="mt-0.5 text-xs text-white/60">
                        <x-station-time :at="now()" format="H:i" /> local
                    </p>
                </div>
            @else
                <div class="rounded-2xl px-3 py-2.5 text-xs text-white/60" style="background: var(--arcs-side-active);">
                    <x-station-time :at="now()" format="H:i" /> UTC
                </div>
            @endif

            <nav class="space-y-4">
                @foreach ($menuGroups as $group => $labels)
                    <div>
                        <p class="mb-1.5 px-2 text-[10px] font-semibold uppercase tracking-wider text-white/35">{{ $group }}</p>
                        <ul class="space-y-1 text-sm">
                            @foreach ($labels as $label)
                                @php $routeName = $menu[$label] ?? null; @endphp
                                @if ($routeName)
                                    <li>
                                        @if (Route::has($routeName))
                                            <a href="{{ route($routeName) }}"
                                               @class([
                                                   'flex min-h-[44px] items-center rounded-full px-3 py-2',
                                                   'font-medium' => request()->routeIs($routeName),
                                                   'text-white/75 hover:bg-white/5' => ! request()->routeIs($routeName),
                                               ])
                                               @style(['background: var(--arcs-side-active)' => request()->routeIs($routeName)])
                                               @if (request()->routeIs($routeName)) aria-current="page" @endif>
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

            @if ($showTodaysInMenu)
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <x-todays-flights :role="$role->value" compact />
                </div>
            @else
                <div class="flex-1"></div>
            @endif

            <div class="mt-auto rounded-2xl p-3" style="background: var(--arcs-side-active);" data-user-card>
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
