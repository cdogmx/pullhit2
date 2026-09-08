<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-secret auth for the browser agent.
 *
 * A bearer token rather than a user session: the extension runs in a browser
 * signed in to eBay, not to us, and giving it a session cookie would hand a
 * page-scraping context the right to act as the user on this site. The token
 * only reaches these three endpoints, and it is compared in constant time so a
 * wrong guess reveals nothing by how long it took to reject.
 */
class EnsureScrapeAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.scrape_agent.token');

        // Unset token must never mean "open" — that would publish the queue.
        if ($expected === '') {
            abort(503, 'The scrape agent is not configured.');
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            abort(401, 'Bad agent token.');
        }

        return $next($request);
    }
}
