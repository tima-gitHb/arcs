<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreAirportRequest;
use App\Http\Requests\Manager\UpdateAirportRequest;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Services\CountryList;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AirportController extends Controller
{
    /**
     * The network, an add form, and one edit form per airport. The code is shown, not edited.
     */
    public function index(): View
    {
        return view('manager.airports', [
            'airports' => Airport::query()->orderBy('code')->get(),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Add an airport. One audit row, "airport MXP added", in the same transaction.
     */
    public function store(StoreAirportRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request) {
            Airport::create($data);
            $this->audit($request, 'airport '.$data['code'].' added', null, $data['code']);
        });

        return redirect()->route('manager.airports')->with('success', 'Airport added.');
    }

    /**
     * Change name, city, country, time zone or the minimum connection.
     * One audit row per column that actually changed, in the same transaction.
     * Country is recorded as its name. The code in the URL is never written.
     */
    public function update(UpdateAirportRequest $request, Airport $airport, CountryList $countries): RedirectResponse
    {
        $data = $request->validated();
        $changed = false;

        DB::transaction(function () use ($airport, $data, $countries, $request, &$changed) {
            $code = $airport->code;

            if ($airport->name !== $data['name']) {
                $this->audit($request, "airport {$code} name", $airport->name, $data['name']);
                $airport->name = $data['name'];
            }

            if ($airport->city !== $data['city']) {
                $this->audit($request, "airport {$code} city", $airport->city, $data['city']);
                $airport->city = $data['city'];
            }

            if ($airport->country !== $data['country']) {
                $this->audit($request, "airport {$code} country", $countries->name($airport->country), $countries->name($data['country']));
                $airport->country = $data['country'];
            }

            if ($airport->timezone !== $data['timezone']) {
                $this->audit($request, "airport {$code} timezone", $airport->timezone, $data['timezone']);
                $airport->timezone = $data['timezone'];
            }

            $minutes = (int) $data['min_connection_minutes'];
            if ($airport->min_connection_minutes !== $minutes) {
                $this->audit($request, "airport {$code} minutes", (string) $airport->min_connection_minutes, (string) $minutes);
                $airport->min_connection_minutes = $minutes;
            }

            $changed = $airport->isDirty();
            $airport->save();
        });

        return redirect()->route('manager.airports')
            ->with('success', $changed ? 'Airport saved.' : 'No airport detail changed.');
    }

    /**
     * An airport belongs to no passenger and no flight, so those columns stay null.
     */
    private function audit(Request $request, string $field, ?string $old, string $new): void
    {
        AuditEntry::create([
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'user_id' => $request->user()->id,
            'changed_at' => now(),
        ]);
    }
}
