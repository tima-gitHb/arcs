<?php

namespace App\Http\Requests\Manager;

use App\Enums\FlightStatus;
use App\Models\Airport;
use App\Models\Flight;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
     * Status is required. The new departure is optional and only meaningful for Delayed:
     * an empty field leaves the departure alone. Whether the status move is allowed
     * stays StatusService's job.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(FlightStatus::class)],
            'departure_at' => ['nullable', 'date'],
        ];
    }

    /**
     * A filled delay time is read as local time at the origin, the same way the create form
     * reads departure_at. It has to be a later instant than the departure already stored.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->newDeparture() === null) {
                return;
            }

            if (! $this->newDeparture()->gt($this->flight()->departure_at)) {
                $validator->errors()->add(
                    'departure_at',
                    'The new departure must be later than the current one.',
                );
            }
        }];
    }

    /**
     * The new departure as an instant, or null when the Delayed control left the field empty.
     * Any other status ignores a posted time. Null means "status only".
     */
    public function newDeparture(): ?Carbon
    {
        if ($this->input('status') !== FlightStatus::Delayed->value || ! $this->filled('departure_at')) {
            return null;
        }

        return Carbon::parse($this->input('departure_at'), Airport::timezoneOf($this->flight()->origin));
    }

    private function flight(): Flight
    {
        $flight = $this->route('flight');

        return $flight instanceof Flight ? $flight : Flight::query()->findOrFail($flight);
    }
}
