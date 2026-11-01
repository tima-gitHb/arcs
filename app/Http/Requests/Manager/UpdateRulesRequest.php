<?php

namespace App\Http\Requests\Manager;

use App\Enums\CabinClass;
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
     * Numbers >= 0 (SR-27, SR-31). The upper limits match the columns:
     * pieces is an unsigned tiny integer (255), max_kg_per_bag is decimal(4,1) (999.9).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [
            'passport_min_months' => ['required', 'integer', 'min:0'],
        ];

        foreach (CabinClass::cases() as $cabin) {
            $key = strtolower($cabin->value);

            $rules["allowances.$key.pieces"] = ['required', 'integer', 'min:0', 'max:255'];
            $rules["allowances.$key.max_kg_per_bag"] = ['required', 'numeric', 'min:0', 'max:999.9'];
        }

        return $rules;
    }
}
