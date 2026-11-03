<?php

namespace App\Http\Controllers\Gate;

use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Services\ManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Live manifest of one flight (G1: SR-11, SR-45).
 */
class ManifestController extends Controller
{
    public function show(Flight $flight, ManifestService $manifest): View
    {
        return view('gate.manifest', [
            'flight' => $flight,
            'counts' => $manifest->counts($flight),
            'passengers' => $manifest->passengers($flight),
        ]);
    }

    /**
     * Polled every 10 seconds by the manifest page.
     */
    public function counts(Flight $flight, ManifestService $manifest): JsonResponse
    {
        return response()->json($manifest->counts($flight) + ['status' => $flight->status->value]);
    }
}
