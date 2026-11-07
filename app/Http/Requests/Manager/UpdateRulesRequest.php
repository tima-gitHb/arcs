<?php

namespace App\Http\Requests\Manager;

use App\Enums\CabinClass;
use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRulesRequest extends FormRequest
{
    /**
     * Who may submit is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The hard limit for one bag (SR-54): the hard_max_kg_per_bag setting, 32 when it is missing.
     * Same fallback as AllowanceService. The Rules page reads it from here too.
     */
    public static function hardMaxKg(): float
    {
        return (float) (Setting::find('hard_max_kg_per_bag')?->value ?? 32);
    }

    /**
     * Numbers >= 0 (SR-27, SR-31). pieces is an unsigned tiny integer (255). kg per bag cannot be above
     * the hard limit (SR-54); it is also kept within decimal(4,1) (999.9) in case the setting is set higher.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $hardMax = min(self::hardMaxKg(), 999.9);

        $rules = [
            'passport_min_months' => ['required', 'integer', 'min:0'],
        ];

        foreach (CabinClass::cases() as $cabin) {
            $key = strtolower($cabin->value);

            $rules["allowances.$key.pieces"] = ['required', 'integer', 'min:0', 'max:255'];
            $rules["allowances.$key.max_kg_per_bag"] = ['required', 'numeric', 'min:0', 'max:'.$hardMax];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allowances.*.max_kg_per_bag.max' => 'Maximum weight per bag cannot be more than :max kg.',
        ];
    }
}
