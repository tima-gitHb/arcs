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
        <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
            <div class="mb-6 px-2 text-center">
                <a href="/" class="text-2xl font-bold tracking-wide" style="color: var(--arcs-bar);">
                    ARCS
                </a>
                <p class="mt-1 text-sm text-gray-500">Airline Reservation &amp; Check-in System</p>
            </div>

            <div class="w-full max-w-md rounded-[14px] border bg-white p-6 shadow-sm sm:p-8" style="border-color: var(--arcs-border);">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
