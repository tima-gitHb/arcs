<?php

namespace App\Http\Requests\Manager;

use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\PassengerType;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFaresRequest extends FormRequest
{
    /**
     * The highest price one field accepts: $99,999.99, in cents.
     */
    public const MAX_CENTS = 9999999;

    /**
     * Who may submit is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * All 9 fares and all 4 fees are required, so the form always sends every price.
     * The keys are the lower-case cabin and passenger type (fares.business.adult)
     * and the FeeCode value (fees.ExtraBag).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (CabinClass::cases() as $cabin) {
            foreach (PassengerType::cases() as $type) {
                $rules['fares.'.strtolower($cabin->value).'.'.strtolower($type->value)] = $this->amountRules();
            }
        }

        foreach (FeeCode::cases() as $code) {
            $rules['fees.'.$code->value] = $this->amountRules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fares.*.*.regex' => 'Enter an amount in dollars, like 19.99 (at most 2 decimals).',
            'fees.*.regex' => 'Enter an amount in dollars, like 19.99 (at most 2 decimals).',
        ];
    }

    /**
     * One price: required, digits with at most 2 decimals (so never negative or a word),
     * and not above $99,999.99. 'bail' stops at the first failure, so the last rule only
     * ever sees text that Money::toCents() accepts.
     *
     * @return array<int, mixed>
     */
    private function amountRules(): array
    {
        return [
            'bail',
            'required',
            'regex:/^\d{1,15}(\.\d{1,2})?$/D',
            function (string $attribute, mixed $value, Closure $fail) {
                if (Money::toCents((string) $value) > self::MAX_CENTS) {
                    $fail('The amount cannot be more than $99,999.99.');
                }
            },
        ];
    }
}
