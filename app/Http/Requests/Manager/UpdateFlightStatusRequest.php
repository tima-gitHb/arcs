<?php

namespace App\Http\Requests\Manager;

use App\Enums\FlightStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFlightStatusRequest extends FormRequest
{
    /**
     * Who may submit is already decided by the role:manager middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only checks the value is a real status. Whether the move is allowed is StatusService's job.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(FlightStatus::class)],
        ];
    }
}
