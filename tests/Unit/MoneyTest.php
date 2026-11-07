<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M7 — Money::toCents(string $dollars): int (SR-56).
 *
 * Accepts digits with at most 2 decimals ("250", "19.99", "0.5"). It works on the text, never on a float,
 * so "19.99" is exactly 1999. Anything else throws InvalidArgumentException.
 */
class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function validAmounts(): array
    {
        return [
            'whole dollars' => ['250', 25000],
            'two decimals' => ['19.99', 1999],
            'one decimal is tens of cents' => ['0.5', 50],
            'one decimal on dollars' => ['5.5', 550],
            'zero' => ['0', 0],
            'zero with decimals' => ['0.00', 0],
            'cents only' => ['0.05', 5],
            'float trap: 19.99 x 100 is 1998.999 as a float' => ['19.99', 1999],
            'float trap: 0.29' => ['0.29', 29],
            'float trap: 1.15' => ['1.15', 115],
            'large' => ['99999.99', 9999999],
            'trailing zero' => ['12.50', 1250],
        ];
    }

    #[DataProvider('validAmounts')]
    public function test_SR56_dollars_become_exact_integer_cents(string $dollars, int $cents): void
    {
        $this->assertSame($cents, Money::toCents($dollars));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'empty' => [''],
            'letters' => ['abc'],
            'negative' => ['-1'],
            'negative with decimals' => ['-0.50'],
            'plus sign' => ['+5'],
            'three decimals' => ['1.234'],
            'three decimals ending in zero' => ['1.230'],
            'comma as thousands separator' => ['1,234.00'],
            'comma as decimal separator' => ['19,99'],
            'exponent' => ['1e3'],
            'no digits before the dot' => ['.5'],
            'no digits after the dot' => ['5.'],
            'only a dot' => ['.'],
            'two dots' => ['1.2.3'],
            'spaces' => [' 250 '],
            'currency symbol' => ['$250'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_anything_else_is_refused(string $dollars): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCents($dollars);
    }

    public function test_format_and_to_cents_are_inverse_for_whole_cents(): void
    {
        foreach ([0, 5, 99, 100, 1999, 25000, 9999999] as $cents) {
            // format() adds "$" and thousands commas; strip them to get plain dollars back.
            $plain = str_replace(['$', ','], '', Money::format($cents));

            $this->assertSame($cents, Money::toCents($plain));
        }
    }
}
