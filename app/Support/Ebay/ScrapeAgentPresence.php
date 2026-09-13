<?php

namespace App\Support\Ebay;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the browser agent is actually there right now.
 *
 * The card page tells people it is "updating" when it queues a refresh. That
 * promise used to be safe because the server did the fetching itself and could
 * always keep it. Now the fetching happens in a browser extension on someone's
 * desktop, which may be closed — so the page has to know whether anything is
 * listening before it says an update is coming, or it shows a spinner for work
 * that will not happen until the machine is switched back on.
 *
 * The agent touches this every time it claims work or reports a result. It
 * claims a batch roughly every couple of minutes, and polls every two minutes
 * even when the queue is empty, so a window several times that is generous
 * without being stale.
 */
class ScrapeAgentPresence
{
    private const KEY = 'ebay:agent:last_seen';

    /** How long after its last word the agent is still presumed present. */
    private const WINDOW_MINUTES = 10;

    /** The agent spoke to us. */
    public function touch(): void
    {
        // Stored well past the window so `lastSeen()` can still report how long
        // it has been gone, rather than the key simply vanishing.
        Cache::put(self::KEY, Carbon::now()->toIso8601String(), Carbon::now()->addDay());
    }

    public function isLive(): bool
    {
        $seen = $this->lastSeen();

        return $seen !== null && $seen->gt(Carbon::now()->subMinutes(self::WINDOW_MINUTES));
    }

    public function lastSeen(): ?Carbon
    {
        $raw = Cache::get(self::KEY);

        return $raw ? Carbon::parse($raw) : null;
    }

    public function windowMinutes(): int
    {
        return self::WINDOW_MINUTES;
    }
}
