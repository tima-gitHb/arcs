<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\PassengerType;
use App\Exceptions\FareNotFound;
use App\Models\Fare;
use App\Models\Fee;

/**
 * The only place to compute a price. It reads the fares and fees tables, so changing a
 * price there (card M7) is all it takes.
 */
class FareService
{
    private const CURRENCY = 'USD';

    /**
     * @param  list<array{cabin_class: CabinClass, type: PassengerType}>  $passengers
     * @param  array{extra_bags?: int, extra_kg?: int, fees?: list<FeeCode>}  $extras
     */
    public function quote(array $passengers, array $extras = []): Quote
    {
        $lines = [];

        foreach ($this->groupPassengers($passengers) as $group) {
            $lines[] = $this->line(
                "{$group['cabin_class']->label()} {$group['type']->label()} fare",
                $group['quantity'],
                $this->fareCents($group['cabin_class'], $group['type']),
            );
        }

        if (($extras['extra_bags'] ?? 0) > 0) {
            $lines[] = $this->feeLine(FeeCode::ExtraBag, $extras['extra_bags']);
        }

        if (($extras['extra_kg'] ?? 0) > 0) {
            $lines[] = $this->feeLine(FeeCode::ExcessKg, $extras['extra_kg']);
        }

        foreach ($extras['fees'] ?? [] as $code) {
            $lines[] = $this->feeLine($code, 1);
        }

        return new Quote($lines, array_sum(array_column($lines, 'amount_cents')), self::CURRENCY);
    }

    /**
     * One line per class and type, in the order they first appear.
     *
     * @param  list<array{cabin_class: CabinClass, type: PassengerType}>  $passengers
     * @return list<array{cabin_class: CabinClass, type: PassengerType, quantity: int}>
     */
    private function groupPassengers(array $passengers): array
    {
        $groups = [];

        foreach ($passengers as $passenger) {
            $key = $passenger['cabin_class']->value.'|'.$passenger['type']->value;
            $groups[$key] ??= $passenger + ['quantity' => 0];
            $groups[$key]['quantity']++;
        }

        return array_values($groups);
    }

    private function fareCents(CabinClass $cabin, PassengerType $type): int
    {
        $cents = Fare::query()->where('cabin_class', $cabin)->where('passenger_type', $type)->value('amount_cents');

        if ($cents === null) {
            throw new FareNotFound(
                "No published fare for {$cabin->label()} {$type->label()}. Ask a manager to set prices (card M7), then try again.",
            );
        }

        return $cents;
    }

    /**
     * @return array{label: string, quantity: int, unit_cents: int, amount_cents: int}
     */
    private function feeLine(FeeCode $code, int $quantity): array
    {
        $cents = Fee::query()->where('code', $code)->value('amount_cents');

        if ($cents === null) {
            throw new FareNotFound("No fee for {$code->label()}.");
        }

        return $this->line($code->label(), $quantity, $cents);
    }

    /**
     * @return array{label: string, quantity: int, unit_cents: int, amount_cents: int}
     */
    private function line(string $label, int $quantity, int $unitCents): array
    {
        return [
            'label' => $label,
            'quantity' => $quantity,
            'unit_cents' => $unitCents,
            'amount_cents' => $quantity * $unitCents,
        ];
    }
}
