<?php

namespace App\Http\Controllers\Manager;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\SetAccountActiveRequest;
use App\Http\Requests\Manager\StoreAccountRequest;
use App\Models\User;
use App\Services\AccountService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function index(): View
    {
        return view('manager.accounts', [
            'users' => User::orderBy('name')->get(),
            'roles' => Role::cases(),
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $user = $this->accounts->create($request->validated());

        return redirect()->route('manager.accounts')
            ->with('success', "Account created. A welcome email was sent to {$user->email}.");
    }

    public function setActive(SetAccountActiveRequest $request, User $user): RedirectResponse
    {
        $active = $request->boolean('active');

        return $this->run(
            fn () => $this->accounts->setActive($user, $active, $request->user()),
            $active ? 'Account activated.' : 'Account deactivated.',
        );
    }

    public function unlock(User $user): RedirectResponse
    {
        return $this->run(fn () => $this->accounts->unlock($user), 'Account unlocked.');
    }

    public function sendResetLink(User $user): RedirectResponse
    {
        return $this->run(fn () => $this->accounts->sendResetLink($user), 'Password reset link sent.');
    }

    /**
     * Run a service action. If the service refuses, show its message as an error.
     */
    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return redirect()->route('manager.accounts')->with('error', $e->getMessage());
        }

        return redirect()->route('manager.accounts')->with('success', $success);
    }
}
