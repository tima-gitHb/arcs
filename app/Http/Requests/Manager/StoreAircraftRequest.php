<?php

namespace App\Http\Requests\Manager;

use App\Support\Limits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAircraftRequest extends FormRequest
{
    /**
     * Who may submit is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $premiumRules = ['nullable', 'integer', 'min:0'];

        // Business + Premium rows must fit in the total rows (SR-54), so Premium may be at most
        // rows - business_rows. Only added when both numbers are usable; otherwise their own rules report it.
        $rows = $this->input('rows');
        $businessRows = $this->input('business_rows');

        if (is_numeric($rows) && is_numeric($businessRows)) {
            $premiumRules[] = 'max:'.max(0, (int) $rows - (int) $businessRows);
        }

        return [
            'registration' => ['required', 'string', 'max:255', Rule::unique('aircraft', 'registration')],
            'model' => ['required', 'string', 'max:255'],
            'rows' => ['required', 'integer', 'between:1,99'],
            'seats_per_row' => ['required', 'integer', 'between:1,10'],
            'business_rows' => ['required', 'integer', 'min:0', 'lte:rows'],
            'premium_rows' => $premiumRules,
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $rows = (int) $this->input('rows');
            $perRow = (int) $this->input('seats_per_row');
            $max = Limits::maxAircraftSeats();

            if ($rows > 0 && $perRow > 0 && ($rows * $perRow) > $max) {
                $validator->errors()->add('rows', "Total seats cannot exceed {$max}.");
            }
        }];
    }

    /**
     * The same text as the safety-net check in AircraftService::create(), under the same key (premium_rows).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'premium_rows.max' => 'Business and Premium rows cannot be more than the total rows.',
        ];
    }
}
