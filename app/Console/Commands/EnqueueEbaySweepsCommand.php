<?php

namespace App\Console\Commands;

use App\Models\EbayScrapeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Put the broad sold sweeps on the browser agent's queue when they come due.
 *
 * The sweeps are the cheapest comps we get: one page of "pokemon psa 10" is a
 * few hundred sales across as many cards, where a per-card job is one card. So
 * they queue above routine per-card work — but below a card someone is looking
 * at right now, which is the only thing a person is waiting on.
 *
 * Run often; each search still honours its own `interval_minutes`, measured
 * from the last time the agent finished that label rather than from when this
 * command last ran, so a stopped agent does not silently skip a sweep's turn.
 */
class EnqueueEbaySweepsCommand extends Command
{
    protected $signature = 'ebay:enqueue-sweeps
        {--force : queue every search now, ignoring its interval}';

    protected $description = 'Queue the broad eBay sold sweeps for the browser agent';

    /** Above the routine per-card top-up, below a card being viewed (100). */
    private const SWEEP_PRIORITY = 50;

    public function handle(): int
    {
        if (! config('valuation.ebay.sweep.enabled')) {
            $this->warn('The eBay sweep is disabled.');

            return self::SUCCESS;
        }

        $queued = 0;
        $rows = [];

        foreach ((array) config('valuation.ebay.sweep.searches', []) as $search) {
            $label = $search['label'] ?? null;

            if (! $label || empty($search['url'])) {
                continue;
            }

            if ($outstanding = $this->outstanding($label)) {
                $rows[] = [$label, 'waiting', 'job #'.$outstanding];

                continue;
            }

            $due = $this->dueAt($label, (int) ($search['interval_minutes'] ?? 60));

            if (! $this->option('force') && $due !== null && $due->isFuture()) {
                $rows[] = [$label, 'not due', $due->diffForHumans()];

                continue;
            }

            EbayScrapeJob::create([
                'kind' => EbayScrapeJob::KIND_SWEEP,
                'label' => $label,
                'url' => $search['url'],
                'status' => EbayScrapeJob::STATUS_PENDING,
                'priority' => self::SWEEP_PRIORITY,
            ]);

            $rows[] = [$label, 'QUEUED', 'every '.($search['interval_minutes'] ?? 60).'m'];
            $queued++;
        }

        if ($rows !== []) {
            $this->table(['Search', 'Action', 'Detail'], $rows);
        }

        $this->line("Queued {$queued} sweep(s). Outstanding queue: ".EbayScrapeJob::outstanding()->count());

        return self::SUCCESS;
    }

    /** The id of a sweep for this label already waiting or in flight, if any. */
    private function outstanding(string $label): ?int
    {
        return EbayScrapeJob::outstanding()
            ->where('kind', EbayScrapeJob::KIND_SWEEP)
            ->where('label', $label)
            ->value('id');
    }

    /**
     * When this search may next run, from the last time the agent actually
     * finished it. Null when it has never run.
     */
    private function dueAt(string $label, int $intervalMinutes): ?Carbon
    {
        $last = EbayScrapeJob::where('kind', EbayScrapeJob::KIND_SWEEP)
            ->where('label', $label)
            ->whereNotNull('completed_at')
            ->max('completed_at');

        return $last ? Carbon::parse($last)->addMinutes($intervalMinutes) : null;
    }
}
