<?php

namespace App\Support\Scanning;

/**
 * Wall-clock for the phases of one scan.
 *
 * Added because we could not answer the only question that matters about scan
 * speed: where does the time go. scan_logs recorded what a scan produced —
 * cards, AI reads, cache hits, credits — and nothing about how long any of it
 * took, so "our scanner feels slow next to theirs" had no measurement behind it
 * and no way to tell whether a change helped.
 *
 * Scoped to the request, so one scan's phases cannot bleed into the next.
 */
class ScanTimer
{
    /** Phase name => milliseconds accumulated. */
    private array $phases = [];

    /**
     * Run $work, adding its wall-clock to $phase.
     *
     * Accumulates rather than overwrites: a bulk scan crops and fingerprints
     * every card in turn, and the interesting number is what that costs in
     * total, not what the last one did.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function time(string $phase, callable $work)
    {
        $started = hrtime(true);

        try {
            return $work();
        } finally {
            // In a finally, so a phase that throws still reports the time it
            // burned. A scan that fails slowly is exactly what we want to see.
            $this->phases[$phase] = ($this->phases[$phase] ?? 0)
                + (int) round((hrtime(true) - $started) / 1_000_000);
        }
    }

    /** Milliseconds recorded for a phase, or null if it never ran. */
    public function ms(string $phase): ?int
    {
        return $this->phases[$phase] ?? null;
    }

    /** @return array<string, int> */
    public function all(): array
    {
        return $this->phases;
    }

    public function reset(): void
    {
        $this->phases = [];
    }
}
