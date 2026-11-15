{{-- PDF bag tag(s) (C15). Plain CSS only — dompdf does not run Tailwind. Expects $tags. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bag tag</title>
    <style>
        @page { margin: 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #111; }
        .tag { page-break-after: always; border: 1px solid #222; padding: 10mm 8mm; }
        .tag:last-child { page-break-after: auto; }
        .dest { font-size: 42pt; font-weight: bold; letter-spacing: 2px; line-height: 1.1; }
        .flags { margin-top: 4mm; font-size: 12pt; font-weight: bold; }
        .flag { display: inline-block; border: 1px solid #111; padding: 1mm 3mm; margin-right: 3mm; }
        .meta { margin-top: 5mm; }
        .meta td { padding: 1mm 4mm 1mm 0; vertical-align: top; }
        .flights { margin-top: 4mm; }
        .flights td { padding: 1mm 6mm 1mm 0; }
        .barcode { margin-top: 6mm; text-align: center; }
        .barcode .num { font-family: DejaVu Sans Mono, monospace; font-size: 12pt; margin-top: 2mm; }
        .stub {
            margin-top: 8mm;
            border-top: 1px dashed #444;
            padding-top: 4mm;
            font-size: 10pt;
        }
        .stub .label { font-size: 8pt; color: #555; text-transform: uppercase; }
        .stub .dest-small { font-size: 18pt; font-weight: bold; }
    </style>
</head>
<body>
@foreach ($tags as $tag)
    @php
        $bag = $tag['bag'];
        $passenger = $tag['passenger'];
        $name = strtoupper($passenger->last_name).' / '.strtoupper($passenger->first_name);
    @endphp
    <div class="tag">
        <div class="dest">{{ $tag['destination'] }}</div>

        @if ($tag['heavy'] || $tag['gateDelivery'])
            <div class="flags">
                @if ($tag['heavy'])
                    <span class="flag">HEAVY</span>
                @endif
                @if ($tag['gateDelivery'])
                    <span class="flag">GATE DELIVERY</span>
                @endif
            </div>
        @endif

        <table class="meta">
            <tr>
                <td><strong>Passenger</strong><br>{{ $name }}</td>
                <td><strong>PNR</strong><br>{{ $passenger->reservation->pnr }}</td>
                <td><strong>Weight</strong><br>{{ $tag['weightLabel'] }} kg</td>
                <td><strong>Type</strong><br>{{ $bag->type->label() }}</td>
            </tr>
        </table>

        <table class="flights">
            <tr>
                <td><strong>Flight</strong></td>
                <td><strong>Route</strong></td>
            </tr>
            @foreach ($tag['flights'] as $flight)
                <tr>
                    <td>{{ $flight->flight_no }}</td>
                    <td>{{ $flight->origin }} → {{ $flight->destination }}</td>
                </tr>
            @endforeach
        </table>

        <div class="barcode">
            {!! $tag['barcodeSvg'] !!}
            <div class="num">{{ $bag->tag_no }}</div>
        </div>

        <div class="stub">
            <div class="label">Passenger stub · tear off</div>
            <table class="meta">
                <tr>
                    <td><strong>Tag</strong><br>{{ $bag->tag_no }}</td>
                    <td><strong>Name</strong><br>{{ $name }}</td>
                    <td><span class="dest-small">{{ $tag['destination'] }}</span></td>
                </tr>
            </table>
        </div>
    </div>
@endforeach
</body>
</html>
