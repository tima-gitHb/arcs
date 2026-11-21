<?php

namespace App\Http\Controllers\Manager;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\SetAccountActiveRequest;
use App\Http\Requests\Manager\StoreAccountRequest;
use App\Http\Requests\Manager\UpdateAccountStationRequest;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\User;
use App\Services\AccountService;
use App\Support\ManagerStation;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function index(Request $request): View
    {
        ManagerStation::apply($request);
        $q = trim((string) $request->query('q', ''));
        $role = $request->query('role');
        $station = $request->query('airport_code');

        $users = User::with('airport')
            ->orderBy('name')
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->when(is_string($role) && $role !== '', fn ($query) => $query->where('role', $role))
            ->when(is_string($station) && $station !== '', fn ($query) => $query->where('airport_code', $station))
            ->paginate(25)
            ->withQueryString();

        return view('manager.accounts', [
            'users' => $users,
            'roles' => Role::cases(),
            'airports' => Airport::query()->orderBy('code')->get(),
            'filters' => [
                'q' => $q,
                'role' => is_string($role) ? $role : '',
                'airport_code' => is_string($station) ? $station : '',
            ],
        ]);
    }

    public function create(): View
    {
        ManagerStation::apply(request());

        return view('manager.accounts-create', [
            'roles' => Role::cases(),
            'airports' => Airport::query()->orderBy('code')->get(),
        ]);
    }

    public function show(User $user): View
    {
        ManagerStation::apply(request());
        $user->load('airport');

        $activity = AuditEntry::query()
            ->where('user_id', $user->id)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('manager.accounts-show', [
            'account' => $user,
            'roles' => Role::cases(),
            'airports' => Airport::query()->orderBy('code')->get(),
            'activity' => $activity,
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $user = $this->accounts->create($request->validated());

        return redirect()->route('manager.accounts.show', $user)
            ->with('success', "Account created. A welcome email was sent to {$user->email}.");
    }

    /**
     * Set or clear the station on an existing account. A blank choice stores null.
     */
    public function updateStation(UpdateAccountStationRequest $request, User $user): RedirectResponse
    {
        $user->update(['airport_code' => $request->validated('airport_code')]);

        return redirect()->route('manager.accounts.show', $user)->with('success', 'Station saved.');
    }

    public function setActive(SetAccountActiveRequest $request, User $user): RedirectResponse
    {
        $active = $request->boolean('active');

        return $this->run(
            $user,
            fn () => $this->accounts->setActive($user, $active, $request->user()),
            $active ? 'Account activated.' : 'Account deactivated.',
        );
    }

    public function unlock(User $user): RedirectResponse
    {
        return $this->run($user, fn () => $this->accounts->unlock($user), 'Account unlocked.');
    }

    public function sendResetLink(User $user): RedirectResponse
    {
        return $this->run($user, fn () => $this->accounts->sendResetLink($user), 'Password reset link sent.');
    }

    public function resendWelcome(User $user): RedirectResponse
    {
        return $this->run($user, fn () => $this->accounts->resendWelcome($user), 'Welcome email sent.');
    }

    /**
     * Run a service action. If the service refuses, show its message as an error.
     */
    private function run(User $user, callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return redirect()->route('manager.accounts.show', $user)->with('error', $e->getMessage());
        }

        return redirect()->route('manager.accounts.show', $user)->with('success', $success);
    }
}
