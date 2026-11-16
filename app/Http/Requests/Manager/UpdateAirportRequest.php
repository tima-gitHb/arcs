<?php

namespace App\Http\Requests\Manager;

class UpdateAirportRequest extends StoreAirportRequest
{
    /**
     * The code is the primary key and other tables point at it, so an edit never accepts one.
     * A posted code is ignored: the airport in the URL stays.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->detailRules();
    }
}
