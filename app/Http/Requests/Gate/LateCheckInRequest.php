<?php

namespace App\Http\Requests\Gate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The late check-in form at the gate (G5, SR-29).
 * The field must stay named "password": Laravel never flashes that name back to the
 * session after a validation error, so the Manager's password is not stored.
 */
class LateCheckInRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'manager_email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'seat_id' => ['nullable', 'integer', 'exists:seats,id'],
            'entry_reason' => ['nullable', 'string', 'max:255'], // kept in audit_log (SR-49)
        ];
    }
}
