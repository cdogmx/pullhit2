<?php

namespace App\Console\Commands;

use App\Actions\Reconcile\ReconcileSet;
use App\Models\PricechartingProduct;
use App\Models\Set;
use Illuminate\Console\Command;

/**
 * Dry-run: diff catalog sets against their PriceCharting products and report the
 * proposed changes by type. The reconciliation's preview step — ApplySet (Phase
 * 5) performs the writes.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'catalog:reconcile
        {--set= : a single set slug (default: every set with PriceCharting products)}
        {--samples=10 : sample rows to show per add type for a single set}';

    protected $description = 'Diff catalog sets against PriceCharting and report proposed changes (dry-run).';

    public function handle(ReconcileSet $reconcile): int
    {
        $single = $this->option('set');

        $sets = $single
            ? Set::where('slug', $single)->get()
            : Set::whereIn('id', PricechartingProduct::whereNotNull('set_id')->distinct()->pluck('set_id'))
                ->orderBy('name')->get();

        if ($sets->isEmpty()) {
            $this->error('No matching sets.');

            return self::FAILURE;
        }

        $grand = [];
        foreach ($sets as $set) {
            $changes = $reconcile($set);
            if ($changes === []) {
                continue;
            }

            $by = collect($changes)->groupBy('action')->map->count();
            foreach ($by as $action => $count) {
                $grand[$action] = ($grand[$action] ?? 0) + $count;
            }

            $adds = ($by['add_printing'] ?? 0) + ($by['add_error'] ?? 0) + ($by['add_card'] ?? 0);
            $this->line(sprintf(
                '%-34s +%-4d printings  ~%-3d labels  ⊕%-3d sealed  →%-4d link  ?%-3d card',
                \Illuminate\Support\Str::limit($set->name, 33),
                $adds,
                $by['fix_label'] ?? 0,
                $by['add_sealed'] ?? 0,
                $by['link'] ?? 0,
                $by['add_card'] ?? 0,
            ));

            if ($single) {
                $this->samples($changes);
            }
        }

        $this->newLine();
        $this->info('Grand totals across '.$sets->count().' sets:');
        foreach ($grand as $action => $count) {
            $this->line("  {$action}: {$count}");
        }

        return self::SUCCESS;
    }

    /** @param  array<int, \App\Support\Reconcile\ReconcileChange>  $changes */
    private function samples(array $changes): void
    {
        $limit = (int) $this->option('samples');
        foreach (['add_printing', 'add_error', 'fix_label', 'add_card', 'add_sealed'] as $action) {
            $rows = array_values(array_filter($changes, fn ($c) => $c->action === $action));
            if ($rows === []) {
                continue;
            }
            $this->line('  <comment>'.$action.'</comment> ('.count($rows).'):');
            foreach (array_slice($rows, 0, $limit) as $c) {
                $attrs = $c->diff !== [] ? json_encode($c->diff) : json_encode($c->attributes);
                $psa10 = $c->prices['psa10'] ?? null;
                $this->line(sprintf('    [%s] %-40s %s  psa10=%s', $c->confidence, \Illuminate\Support\Str::limit($c->label, 40), $attrs, $psa10 ? '$'.number_format($psa10 / 100) : '—'));
            }
        }
    }
}
