<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForcePasswordChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * M20: after logging in with a temporary password, choose a permanent one.
 */
class ForcePasswordChangeController extends Controller
{
    public function edit(): View
    {
        return view('auth.force-change-password');
    }

    public function update(ForcePasswordChangeRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => false,
        ])->save();

        return redirect()->route($user->role->homeRoute());
    }
}
