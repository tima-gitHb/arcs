<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public const GENERIC_MESSAGE = 'If that email exists in ARCS, a password reset link has been sent to it.';

    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // SR-37 / SR-35: only an active, unlocked account gets a link, so a reset can never
        // get round a lock (only the Manager unlocks). Everyone sees the same message,
        // so the page does not reveal which emails exist or which accounts are locked.
        $user = User::where('email', $request->string('email'))->first();

        if ($user && $user->active && ! $user->locked) {
            Password::sendResetLink($request->only('email'));
        }

        return back()->with('status', self::GENERIC_MESSAGE);
    }
}
