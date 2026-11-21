{{--
    Print all active boarding passes for a PNR (C12 / SR-61).
    Expects $reservation and $cards (list of printData arrays).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Boarding passes {{ $reservation->pnr }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none; }
            body { background: white; }
            .pass-break { break-after: page; }
        }
    </style>
</head>
<body class="font-sans text-gray-900 antialiased" style="background: var(--arcs-page);">
    <div class="mx-auto max-w-2xl space-y-8 p-6">
        <div class="no-print flex items-center justify-between">
            <p class="text-sm text-gray-600">PNR {{ $reservation->pnr }} · {{ $cards->count() }} passes</p>
            <button type="button" onclick="window.print()" class="arcs-btn-primary">Print</button>
        </div>

        @foreach ($cards as $card)
            <div @class(['pass-break' => ! $loop->last])>
                @include('checkin.partials.boarding-pass-sheet', $card)
            </div>
        @endforeach
    </div>
</body>
</html>
