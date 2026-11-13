<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf as DomPdf;
use Illuminate\Http\Response;

/**
 * Every ARCS PDF is made here (B13c), from a Blade view, with dompdf.
 *     return Pdf::fromView('checkin.pdf.boarding-pass', ['pass' => $pass], 'boarding-pass-K7M2NP.pdf');  // download
 *     $bytes = Pdf::raw('reservation.pdf.eticket', ['reservation' => $reservation]);                      // email attachment
 * PDF views are complete HTML pages with inline or <style> CSS: dompdf does not run Tailwind or JavaScript.
 * Paper is A4 portrait unless a view says otherwise with CSS @page.
 */
final class Pdf
{
    /**
     * A download response (Content-Type application/pdf, attachment; filename=...).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromView(string $view, array $data, string $filename): Response
    {
        return DomPdf::loadView($view, $data)->setPaper('a4')->download($filename);
    }

    /**
     * The PDF itself, as a string: for Attachment::fromData() in a mailable.
     *
     * @param  array<string, mixed>  $data
     */
    public static function raw(string $view, array $data): string
    {
        return DomPdf::loadView($view, $data)->setPaper('a4')->output();
    }
}
