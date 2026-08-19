<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'You are not authorized to perform this action.');
        }

        // Load roles once to avoid N+1 queries on subsequent hasRole calls
        if (! $user->relationLoaded('roles')) {
            $user->load('roles');
        }

        if (! $user->hasAnyRole($roles)) {
            abort(403, 'You are not authorized to perform this action.');
        }

        return $next($request);
    }
}
