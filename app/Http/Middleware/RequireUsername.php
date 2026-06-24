<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts created via social login have no username yet. Until they pick one,
 * route every page to the "choose a username" screen (the OAuth callback, the
 * username routes themselves, and logout are exempt to avoid a redirect loop).
 */
class RequireUsername
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user
            && ($user->username === null || $user->username === '')
            && ! $request->routeIs('username.*')
            && ! $request->routeIs('logout')
            && ! $request->is('auth/*')
            && $request->isMethod('GET')
        ) {
            return redirect()->route('username.edit');
        }

        return $next($request);
    }
}
