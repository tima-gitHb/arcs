<?php

namespace App\Http\Requests\Manager;

use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\PassengerType;
use App\Models\Airport;
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
     * "bey-cdg" is the same route as "BEY-CDG". An empty route is the default price list.
     */
    protected function prepareForValidation(): void
    {
        $route = $this->input('route');

        if (is_string($route)) {
            $this->merge(['route' => strtoupper(trim($route))]);
        }
    }

    /**
     * All 9 fares and all 4 fees are sent. On the default list every fare is required.
     * On a route an empty fare cell is allowed: it means "no price of its own".
     * The keys are the lower-case cabin and passenger type (fares.business.adult)
     * and the FeeCode value (fees.ExtraBag). route is "BEY-CDG", or empty for the defaults.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'route' => ['nullable', 'string', $this->routeRule()],
        ];

        foreach (CabinClass::cases() as $cabin) {
            foreach (PassengerType::cases() as $type) {
                $rules['fares.'.strtolower($cabin->value).'.'.strtolower($type->value)] = $this->amountRules(! $this->onRoute());
            }
        }

        foreach (FeeCode::cases() as $code) {
            $rules['fees.'.$code->value] = $this->amountRules(true);
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
            'route' => 'Choose a route like BEY-CDG, with two different airports from the network.',
        ];
    }

    /**
     * A posted route (not the default list). Empty fare cells are then allowed.
     */
    private function onRoute(): bool
    {
        $route = $this->input('route');

        return is_string($route) && $route !== '';
    }

    /**
     * XXX-YYY is not a network route, BEY-BEY is not a trip, and BEY or BEYCDG is not a route.
     */
    private function routeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if (! preg_match('/^[A-Z]{3}-[A-Z]{3}$/D', $value)) {
                $fail('Choose a route like BEY-CDG.');

                return;
            }

            [$origin, $destination] = explode('-', $value);

            if ($origin === $destination) {
                $fail('The origin and the destination must be different airports.');

                return;
            }

            if (Airport::query()->whereIn('code', [$origin, $destination])->count() !== 2) {
                $fail('Choose two airports from the network.');
            }
        };
    }

    /**
     * One price: digits with at most 2 decimals (so never negative or a word),
     * and not above $99,999.99. 'bail' stops at the first failure, so the last rule only
     * ever sees text that Money::toCents() accepts. On a route the cell may be empty.
     *
     * @return array<int, mixed>
     */
    private function amountRules(bool $required): array
    {
        return [
            'bail',
            $required ? 'required' : 'nullable',
            'regex:/^\d{1,15}(\.\d{1,2})?$/D',
            function (string $attribute, mixed $value, Closure $fail) {
                if (Money::toCents((string) $value) > self::MAX_CENTS) {
                    $fail('The amount cannot be more than $99,999.99.');
                }
            },
        ];
    }
}
