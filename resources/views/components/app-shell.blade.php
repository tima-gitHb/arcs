{{--
    App shell: same chrome as layouts/app. Prefer <x-app-layout> for pages.
    Kept for pages that already use this component.
--}}
@props([
    'module' => null,
    'station' => null,
])

@php
    // Delegate to the main layout look by rendering the same slots via a lightweight include path.
    $user = auth()->user();
    $role = $user->role;
    $moduleLabel = $module ?? $role->label();
    $stationCode = $station ?? $user->airport_code;
@endphp
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
<body class="font-sans antialiased text-gray-900" style="background: var(--arcs-page);">
<div data-arcs-shell class="arcs-shell flex min-h-screen flex-col">
    <header class="flex flex-wrap items-center gap-3 px-4 py-3 text-white sm:px-6" style="background: var(--arcs-bar);">
        <a href="{{ route($role->homeRoute()) }}" class="text-lg font-semibold tracking-wide">ARCS</a>
        <span class="rounded px-2.5 py-0.5 text-xs font-semibold" style="background: var(--arcs-side-active);" data-role-badge>{{ $moduleLabel }}</span>
        @if ($stationCode)
            <span class="text-xs text-white/70">Station {{ $stationCode }} · <x-station-time :at="now()" format="H:i" /></span>
        @endif
        <div class="min-w-[8rem] flex-1">{{ $search ?? '' }}</div>
        <div class="ml-auto flex items-center gap-3 text-sm">
            <span>{{ $user->name }}</span>
            <a href="{{ route('profile.edit') }}" class="hover:underline">Profile</a>
            <form method="POST" action="{{ route('logout') }}">@csrf
                <button type="submit" class="rounded border border-white/30 px-3 py-1 text-xs hover:bg-white/10">Log out</button>
            </form>
        </div>
    </header>
    <x-flash />
    <div class="flex flex-1 flex-col md:flex-row">
        <nav class="w-full shrink-0 p-3 text-white md:w-56" style="background: var(--arcs-side);">
            @isset($side)
                {{ $side }}
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($role->menu() as $label => $routeName)
                        <li>
                            @if (Route::has($routeName))
                                <a href="{{ route($routeName) }}"
                                   @class(['block rounded-lg px-3 py-2.5 min-h-[44px] flex items-center', 'font-medium' => request()->routeIs($routeName), 'text-white/80 hover:bg-white/5' => ! request()->routeIs($routeName)])
                                   @style(['background: var(--arcs-side-active)' => request()->routeIs($routeName)])
                                   @if (request()->routeIs($routeName)) aria-current="page" @endif>{{ $label }}</a>
                            @else
                                <span class="block rounded-lg px-3 py-2.5 text-white/35">{{ $label }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endisset
        </nav>
        <main class="min-w-0 flex-1 p-4 sm:p-6">{{ $slot }}</main>
        @isset($aside)
            <aside class="w-full shrink-0 p-4 md:w-72 lg:w-80">{{ $aside }}</aside>
        @endisset
    </div>
</div>
</body>
</html>
