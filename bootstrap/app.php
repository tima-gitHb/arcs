<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Middleware\EnsureStationFlight;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'station' => EnsureStationFlight::class,
        ]);

        // SR-39: a guest who still has the login marker cookie had a session that timed out.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->cookie(AuthenticatedSessionController::SESSION_MARKER)) {
                $request->session()->flash('status', 'Session expired, please log in again.');
                Cookie::queue(Cookie::forget(AuthenticatedSessionController::SESSION_MARKER));
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Card data must never reach the session, even after a validation error.
        $exceptions->dontFlash(['card_number', 'card_cvc', 'card_expiry']);
    })->create();
