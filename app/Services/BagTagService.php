<?php

namespace App\Services;

use App\Enums\BagHandling;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\User;
use App\Support\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * PDF bag tags at check-in (C15): final destination, direction itinerary, Code 128, tear-off stub.
 */
class BagTagService
{
    public const HEAVY_KG = 23.0;

    public function __construct(
        private BagService $bags,
        private JourneyService $journeys,
    ) {}

    /**
     * Rotate the tag number then return a fresh PDF (reprint).
     */
    public function reprint(Bag $bag, User $by): Response
    {
        $bag = $this->bags->reprintTag($bag, $by);

        return $this->download($bag);
    }

    /**
     * One PDF for this bag.
     */
    public function download(Bag $bag): Response
    {
        $bag->loadMissing(['passenger.reservation']);

        return Pdf::fromView('checkin.pdf.bag-tag', [
            'tags' => collect([$this->tagData($bag)]),
        ], 'bag-tag-'.$bag->tag_no.'.pdf');
    }

    /**
     * PDF bytes for one bag tag (email attachment, C17). Same view as download().
     */
    public function pdfBytes(Bag $bag): string
    {
        $bag->loadMissing(['passenger.reservation']);

        return Pdf::raw('checkin.pdf.bag-tag', [
            'tags' => collect([$this->tagData($bag)]),
        ]);
    }

    /**
     * One PDF with a tag page (section) for every bag of the passenger.
     */
    public function downloadAll(Passenger $passenger): Response
    {
        $passenger->loadMissing(['bags', 'reservation']);
        $tags = $passenger->bags->map(fn (Bag $bag) => $this->tagData($bag->setRelation('passenger', $passenger)));

        $pnr = $passenger->reservation->pnr;

        return Pdf::fromView('checkin.pdf.bag-tag', [
            'tags' => $tags,
        ], 'bag-tags-'.$pnr.'.pdf');
    }

    /**
     * @return array{
     *     bag: Bag,
     *     passenger: Passenger,
     *     destination: string,
     *     flights: Collection<int, Flight>,
     *     barcodeSvg: string,
     *     heavy: bool,
     *     gateDelivery: bool,
     *     weightLabel: string
     * }
     */
    public function tagData(Bag $bag): array
    {
        $bag->loadMissing(['passenger.reservation']);
        $passenger = $bag->passenger;
        $flights = $this->journeys->flightsOf($passenger->reservation, $bag->direction);

        return [
            'bag' => $bag,
            'passenger' => $passenger,
            'destination' => $bag->destination,
            'flights' => $flights,
            'barcodeSvg' => $this->barcodeSvg($bag->tag_no),
            'heavy' => (float) $bag->weight_kg > self::HEAVY_KG,
            'gateDelivery' => $bag->handling === BagHandling::GateDelivery,
            'weightLabel' => rtrim(rtrim(number_format((float) $bag->weight_kg, 1, '.', ''), '0'), '.'),
        ];
    }

    /**
     * Code 128 of the tag number (same picqer stack as the boarding pass).
     */
    public function barcodeSvg(string $tagNo): string
    {
        $barcode = (new TypeCode128)->getBarcode($tagNo);

        return (new SvgRenderer)->render($barcode, 280, 50);
    }
}
