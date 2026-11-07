<?php

namespace App\Http\Controllers\Manager;

use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\PassengerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\UpdateFaresRequest;
use App\Models\AuditEntry;
use App\Models\Fare;
use App\Models\Fee;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FareController extends Controller
{
    public function index(): View
    {
        // Prices are stored in cents; the form shows plain dollars ("187.50") so it can be posted back as typed.
        $fares = Fare::all()->mapWithKeys(fn (Fare $fare) => [
            strtolower($fare->cabin_class->value).'.'.strtolower($fare->passenger_type->value) => $this->dollars($fare->amount_cents),
        ]);

        $fees = Fee::all()->mapWithKeys(fn (Fee $fee) => [$fee->code->value => $this->dollars($fee->amount_cents)]);

        return view('manager.fares', [
            'cabins' => CabinClass::cases(),
            'types' => PassengerType::cases(),
            'feeCodes' => FeeCode::cases(),
            'fares' => $fares,
            'fees' => $fees,
        ]);
    }

    /**
     * Updates the existing 13 prices. It never creates or deletes a row: FareService needs all of them.
     * Each value that really changed is saved with one audit row (old → new, who, when).
     */
    public function update(UpdateFaresRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $changed = 0;

        // One transaction: either every change and its audit row is saved, or none.
        DB::transaction(function () use ($data, &$changed) {
            foreach (CabinClass::cases() as $cabin) {
                foreach (PassengerType::cases() as $type) {
                    $fare = Fare::where('cabin_class', $cabin)->where('passenger_type', $type)->firstOrFail();
                    $typed = $data['fares'][strtolower($cabin->value)][strtolower($type->value)];

                    $changed += $this->save($fare, $typed, "fare {$cabin->label()} {$type->label()}");
                }
            }

            foreach (FeeCode::cases() as $code) {
                $fee = Fee::where('code', $code)->firstOrFail();

                $changed += $this->save($fee, $data['fees'][$code->value], "fee {$code->value}");
            }
        });

        return redirect()->route('manager.fares')
            ->with('status', $changed === 0 ? 'No price changed.' : "Prices saved ({$changed} changed).");
    }

    /**
     * Saves one price if it differs, with its audit row. Returns 1 when it changed, else 0.
     * "250" and "250.00" are the same 25000 cents, so they are not a change.
     */
    private function save(Fare|Fee $price, string $typedDollars, string $field): int
    {
        $newCents = Money::toCents($typedDollars);

        if ($newCents === $price->amount_cents) {
            return 0;
        }

        AuditEntry::create([
            'field' => $field,
            'old_value' => Money::format($price->amount_cents),
            'new_value' => Money::format($newCents),
            'user_id' => auth()->id(),
            'changed_at' => now(),
            // passenger_id and flight_id stay null: a price belongs to neither.
        ]);

        $price->update(['amount_cents' => $newCents]);

        return 1;
    }

    /**
     * 18750 → "187.50", with integer math only (no float, no thousands comma).
     */
    private function dollars(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
