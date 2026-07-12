<?php

namespace App\Console\Commands;

use App\Jobs\RefreshEbaySoldComps;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use Illuminate\Console\Command;

/**
 * Proactively price generated foil variants: queue an eBay sold-comp refresh for
 * each foil single worth at least --min-value, so the higher-value foils get real
 * prices without waiting for someone to view them. Low-value foils are left to
 * price on-view. Respects the daily Oxylabs cap (the job self-throttles).
 */
class RefreshFoilValuesCommand extends Command
{
    protected $signature = 'catalog:refresh-foils
        {--line=lorcana : product line slug}
        {--min-value=500 : only foils whose seeded value is at least this (cents)}
        {--limit= : cap how many to queue}';

    protected $description = 'Queue an eBay refresh for high-value foil variants';

    public function handle(): int
    {
        $line = ProductLine::where('slug', $this->option('line'))->first();
        if (! $line) {
            $this->error("No product line [{$this->option('line')}].");

            return self::FAILURE;
        }

        $min = (int) $this->option('min-value');

        $ids = CatalogItem::query()
            ->where('product_line_id', $line->id)
            ->where('item_type', 'single')
            ->where('attributes->variant', 'foil')
            ->whereHas('defaultMarketValue', fn ($q) => $q->where('median', '>=', $min))
            ->when($this->option('limit'), fn ($q, $n) => $q->limit((int) $n))
            ->pluck('id');

        foreach ($ids as $id) {
            RefreshEbaySoldComps::dispatch($id);
        }

        $this->info("Queued {$ids->count()} foil refresh(es) for {$line->name} (>= \${$min}c). They drain under the daily cap.");

        return self::SUCCESS;
    }
}
