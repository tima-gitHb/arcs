<?php

namespace App\Http\Requests\Manager;

use App\Services\CountryList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAirportRequest extends FormRequest
{
    /**
     * Who may submit is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "mxp" is accepted as "MXP": uppercase before the 3-letter rule runs.
     * An empty code is already null, so it stays missing and the required rule rejects it.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Z]{3}$/', Rule::unique('airports', 'code')],
        ] + $this->detailRules();
    }

    /**
     * The columns a manager may type. Shared with the edit form, which cannot change the code.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function detailRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:80'],
            'country' => ['required', 'string', 'size:2', Rule::in(app(CountryList::class)->codes())],
            'timezone' => ['required', 'timezone'],
            'min_connection_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ];
    }
}
