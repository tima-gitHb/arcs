{{--
    Printable boarding pass (C5: SR-21, SR-42). Standalone page, opened in a new tab.
    Gate and seat are read now, so a reprint after a change shows the new values.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Boarding pass {{ $flight->flight_no }} {{ $passenger->last_name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none; }
            body { background: white; }
        }
    </style>
</head>
<body class="bg-page font-sans text-gray-900 antialiased">
    <div class="mx-auto max-w-2xl p-6">
        <div class="no-print mb-4 flex justify-end">
            <button type="button" onclick="window.print()" class="rounded bg-navy px-4 py-2 text-sm font-medium text-white">Print</button>
        </div>

        @include('checkin.partials.boarding-pass-sheet')
    </div>
</body>
</html>
