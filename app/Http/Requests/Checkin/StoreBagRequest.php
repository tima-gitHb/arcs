<?php

namespace App\Http\Requests\Checkin;

use App\Enums\BagType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The "Add bag" form on the check-in screen (C4).
 */
class StoreBagRequest extends FormRequest
{
    protected $errorBag = 'addBag';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'weight_kg' => ['required', 'numeric', 'min:0.1', 'max:99.9'],
            'type' => ['required', Rule::enum(BagType::class)],
        ];
    }
}
