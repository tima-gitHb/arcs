<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Allow the request only if the user has one of the given roles (SR-13).
     * Usage: ->middleware('role:checkin') or ->middleware('role:checkin,gate').
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role?->value;

        if (! in_array($role, $roles, true)) {
            abort(403);
        }

        return $next($request);
    }
}
