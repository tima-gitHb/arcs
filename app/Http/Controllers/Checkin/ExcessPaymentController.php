<?php

namespace App\Http\Controllers\Checkin;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Passenger;
use App\Services\BagService;
use App\Services\Payments\CardDetails;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Collect pooled excess at the check-in desk (C11 / SR-60).
 */
class ExcessPaymentController extends Controller
{
    public function store(Request $request, Passenger $passenger, BagService $bags, PaymentService $payments): RedirectResponse
    {
        $screen = redirect()->route('checkin.passengers.show', $passenger);

        $request->validate([
            'card_number' => ['required', 'string', 'max:19'],
            'card_expiry' => ['required', 'string', 'max:5'],
            'card_cvc' => ['required', 'string', 'max:4'],
            'card_name' => ['nullable', 'string', 'max:80'],
        ]);

        $safeInput = fn () => $request->except(['card_number', 'card_cvc', 'card_expiry', 'card_name']);

        try {
            $payment = DB::transaction(function () use ($request, $passenger, $bags, $payments) {
                $locked = Passenger::query()->lockForUpdate()->findOrFail($passenger->id);
                $due = $bags->excessDueCents($locked);

                if ($due <= 0) {
                    return null;
                }

                return $payments->charge(
                    new CardDetails(
                        $request->input('card_number'),
                        $request->input('card_expiry'),
                        $request->input('card_cvc'),
                    ),
                    $due,
                    PaymentKind::ExcessBaggage,
                    $request->user(),
                    $locked->reservation,
                    $locked,
                );
            });
        } catch (\InvalidArgumentException $exception) {
            return $screen->withInput($safeInput())->with('error', $exception->getMessage());
        }

        if ($payment === null) {
            return $screen->withInput($safeInput())->with('error', 'No excess payment is due.');
        }

        if ($payment->status !== PaymentStatus::Succeeded) {
            return $screen->withInput($safeInput())->with('error', 'Card declined');
        }

        return $screen
            ->with('success', 'Excess baggage paid.')
            ->with('excess_payment_id', $payment->id);
    }
}
