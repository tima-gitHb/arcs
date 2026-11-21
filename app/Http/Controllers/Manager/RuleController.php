<?php

namespace App\Http\Controllers\Manager;

use App\Enums\CabinClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\UpdateRulesRequest;
use App\Models\BaggageAllowance;
use App\Models\Setting;
use App\Support\ManagerStation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RuleController extends Controller
{
    public function index(): View
    {
        ManagerStation::apply(request());

        return view('manager.rules', $this->payload());
    }

    public function edit(string $group): View
    {
        ManagerStation::apply(request());

        if (! in_array($group, ['passengers', 'baggage', 'fleet', 'checkin'], true)) {
            abort(404);
        }

        return view('manager.rules-edit', array_merge($this->payload(), ['group' => $group]));
    }

    public function update(UpdateRulesRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // One transaction: either every rule is saved, or none.
        DB::transaction(function () use ($data) {
            Setting::updateOrCreate(
                ['key' => 'passport_min_months'],
                ['value' => (string) $data['passport_min_months']],
            );

            foreach (CabinClass::cases() as $cabin) {
                $values = $data['allowances'][strtolower($cabin->value)];

                BaggageAllowance::updateOrCreate(
                    ['cabin_class' => $cabin],
                    ['pieces' => $values['pieces'], 'max_kg_per_bag' => $values['max_kg_per_bag']],
                );
            }
        });

        return redirect()->route('manager.rules')->with('status', 'Rules saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'hardMaxKg' => UpdateRulesRequest::hardMaxKg(),
            'passportMinMonths' => Setting::find('passport_min_months')?->value,
            // Keyed by lower-case cabin name ('economy', 'business') so the view can look them up.
            'allowances' => BaggageAllowance::all()
                ->keyBy(fn (BaggageAllowance $allowance) => strtolower($allowance->cabin_class->value)),
        ];
    }
}
