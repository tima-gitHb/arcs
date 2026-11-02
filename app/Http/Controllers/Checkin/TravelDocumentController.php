<?php

namespace App\Http\Controllers\Checkin;

use App\Exceptions\DocumentsLocked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkin\StoreTravelDocumentRequest;
use App\Http\Requests\Checkin\UpdateTravelDocumentRequest;
use App\Models\Passenger;
use App\Models\TravelDocument;
use App\Services\CorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Travel documents panel on the check-in screen (C2: SR-23, SR-31, SR-34).
 * Every change goes through CorrectionService, which audits it and refuses it unless
 * the passenger is Booked or CheckedIn. Validity is checked by StatusService::checkIn()
 * on "Confirm check-in", not here.
 */
class TravelDocumentController extends Controller
{
    public function store(StoreTravelDocumentRequest $request, Passenger $passenger, CorrectionService $corrections): RedirectResponse
    {
        try {
            $document = $corrections->addDocument($passenger, $request->validated(), $request->user());
        } catch (DocumentsLocked $exception) {
            return $this->backToScreen($passenger)->with('error', $exception->getMessage());
        }

        return $this->backToScreen($passenger)->with('success', $document->type->label().' added.');
    }

    public function update(UpdateTravelDocumentRequest $request, TravelDocument $document, CorrectionService $corrections): RedirectResponse
    {
        try {
            $corrections->correctDocument($document, $request->validated(), $request->user());
        } catch (DocumentsLocked $exception) {
            return $this->backToScreen($document->passenger)->with('error', $exception->getMessage());
        }

        return $this->backToScreen($document->passenger)->with('success', $document->type->label().' saved.');
    }

    public function verify(Request $request, TravelDocument $document, CorrectionService $corrections): RedirectResponse
    {
        try {
            $corrections->verifyDocument($document, $request->user());
        } catch (DocumentsLocked $exception) {
            return $this->backToScreen($document->passenger)->with('error', $exception->getMessage());
        }

        return $this->backToScreen($document->passenger)->with('success', $document->type->label().' verified.');
    }

    private function backToScreen(Passenger $passenger): RedirectResponse
    {
        return redirect()->route('checkin.passengers.show', $passenger);
    }
}
