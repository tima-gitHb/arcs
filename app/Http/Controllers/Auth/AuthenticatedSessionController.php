<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Cookie set at login that outlives the 15-minute session (SR-39). If a guest still has it,
     * their session timed out, so the login page says "Session expired" (see bootstrap/app.php).
     */
    public const SESSION_MARKER = 'arcs_had_session';

    private const SESSION_MARKER_MINUTES = 12 * 60;

    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        Cookie::queue(self::SESSION_MARKER, '1', self::SESSION_MARKER_MINUTES);

        return redirect()->intended(route($request->user()->role->homeRoute(), absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        Cookie::queue(Cookie::forget(self::SESSION_MARKER));

        return redirect('/');
    }
}
