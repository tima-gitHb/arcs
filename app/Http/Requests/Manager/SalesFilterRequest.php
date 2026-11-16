<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;

class SalesFilterRequest extends FormRequest
{
    /**
     * Who may open the page is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every filter is optional. An empty field arrives as null and means "no filter",
     * so the page with nothing filled in is still today's sales.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'route' => ['nullable', 'regex:/^[A-Z]{3}-[A-Z]{3}$/'],
            'station' => ['nullable', 'string', 'size:3', 'exists:airports,code'],
            'flight' => ['nullable', 'integer', 'exists:flights,id'],
        ];
    }
}
