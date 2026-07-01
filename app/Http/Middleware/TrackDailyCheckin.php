<?php

namespace App\Http\Middleware;

use App\Actions\Community\RecordDailyCheckin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Credits a signed-in user's daily check-in on their first real page view of the
 * day. Runs after the response; skips JSON/XHR so in-app fetch calls don't count.
 * The action itself no-ops cheaply once the day is already recorded.
 */
class TrackDailyCheckin
{
    public function __construct(protected RecordDailyCheckin $checkin) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && ! $request->expectsJson() && ($user = $request->user())) {
            ($this->checkin)($user);
        }

        return $response;
    }
}
