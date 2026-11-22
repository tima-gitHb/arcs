{{-- PDF boarding pass (C17). Plain CSS only — dompdf does not run Tailwind. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Boarding pass {{ $flight->flight_no }}</title>
    <style>
        @page { margin: 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #111; }
        .header {
            background: #0B2A4A;
            color: #fff;
            padding: 8mm 6mm;
            margin: -14mm -14mm 8mm -14mm;
        }
        .header .brand { font-size: 16pt; font-weight: bold; letter-spacing: 1px; }
        .header .title { float: right; font-size: 10pt; text-transform: uppercase; letter-spacing: 2px; }
        .label { font-size: 8pt; color: #555; text-transform: uppercase; margin-bottom: 1mm; }
        .value { font-size: 13pt; font-weight: bold; }
        .value-lg { font-size: 18pt; font-weight: bold; color: #0B2A4A; }
        table.fields { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        table.fields td { width: 33%; vertical-align: top; padding: 3mm 4mm 3mm 0; }
        .name { font-size: 16pt; font-weight: bold; margin-bottom: 4mm; }
        .barcode { margin-top: 8mm; text-align: center; border-top: 1px dashed #888; padding-top: 5mm; }
        .barcode .num { font-family: DejaVu Sans Mono, monospace; font-size: 9pt; color: #444; margin-top: 2mm; }
    </style>
</head>
<body>
    <div class="header">
        <span class="brand">{{ config('arcs.airline.name') }}</span>
        <span class="title">Boarding pass</span>
    </div>

    <div class="name">{{ strtoupper($passenger->last_name) }} / {{ strtoupper($passenger->first_name) }}</div>

    <table class="fields">
        <tr>
            <td>
                <div class="label">Flight</div>
                <div class="value">{{ $flight->flight_no }}</div>
            </td>
            <td>
                <div class="label">From → To</div>
                <div class="value">{{ $flight->origin }} → {{ $flight->destination }}</div>
            </td>
            <td>
                <div class="label">Date</div>
                <div class="value"><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="d M Y" /></div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Gate</div>
                <div class="value-lg">{{ $flight->gate ?? 'TBA' }}</div>
            </td>
            <td>
                <div class="label">Boarding</div>
                <div class="value-lg"><x-local-time :at="$boardingTime" :airport="$flight->origin" format="H:i" /></div>
            </td>
            <td>
                <div class="label">Departure</div>
                <div class="value"><x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="H:i" /></div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Seat</div>
                @if ($passenger->type === \App\Enums\PassengerType::Infant)
                    <div class="value">Infant</div>
                @else
                    <div class="value-lg">{{ $passenger->seatAssignment?->seat->seat_no ?? '—' }}</div>
                @endif
            </td>
            <td>
                <div class="label">PNR</div>
                <div class="value">{{ $passenger->reservation->pnr }}</div>
            </td>
            <td>
                <div class="label">Issued</div>
                <div class="value"><x-station-time :at="$pass->issued_at" format="d M H:i" /></div>
            </td>
        </tr>
    </table>

    <div class="barcode" data-barcode="{{ $pass->barcode }}">
        {!! $barcodeSvg !!}
        <div class="num">{{ $pass->barcode }}</div>
    </div>
</body>
</html>
