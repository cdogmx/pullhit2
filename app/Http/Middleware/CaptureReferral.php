<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the referrer's handle from a `?ref=<username>` link (on any page,
 * once) so registration can credit them. Cleared when consumed at sign-up.
 */
class CaptureReferral
{
    public function handle(Request $request, Closure $next): Response
    {
        $ref = $request->query('ref');

        if (is_string($ref) && $ref !== '' && ! $request->session()->has('referral')) {
            $request->session()->put('referral', mb_substr($ref, 0, 30));
        }

        return $next($request);
    }
}
