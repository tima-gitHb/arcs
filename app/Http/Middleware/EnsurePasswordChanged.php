<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M20: a user with must_change_password may only open the force-change page or log out.
 * Login still goes to the role home first; this middleware then redirects.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs('password.force', 'password.force.update', 'logout')) {
            return $next($request);
        }

        return redirect()->route('password.force');
    }
}
