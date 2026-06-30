<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the PriceCharting reference fresh (the guide regenerates ~daily), then
// apply any new structural changes (new sets/editions). reconcile --apply is
// idempotent, so after the initial backfill the weekly pass is light.
Schedule::command('catalog:pricecharting-import')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('catalog:reconcile --apply')->weeklyOn(1, '08:00')->withoutOverlapping();

// Alert users when a wishlisted card drops to/below their target price.
Schedule::command('wishlist:check-targets')->dailyAt('09:00')->withoutOverlapping();

// Daily value + portfolio snapshots (price-history seam). Runs after the
// PriceCharting import so the day's medians are fresh.
Schedule::command('valuation:snapshot')->dailyAt('06:30')->withoutOverlapping();

// Valuation recompute seam (§7). Wire on Laravel Cloud's scheduler when ready —
// hot items hourly, the long tail daily. Kept off by default.
// Schedule::command('valuation:recompute')->hourly();

// Broad eBay sold-comp sweeps. Ticks often; each configured search self-throttles
// to its own interval (config valuation.ebay.sweep), so this stays under the
// daily Oxylabs cap. Needs Laravel Cloud's scheduler enabled to run.
Schedule::command('valuation:sweep-ebay')->everyTenMinutes()->withoutOverlapping(15);

// Proactive sealed-product comps. The broad sweep is collector-number-based and
// skips sealed, so warm the valuable, stale sealed SKUs by name in small hourly
// batches under the shared daily Oxylabs cap.
Schedule::command('valuation:sweep-sealed')->hourly()->withoutOverlapping(55);

// Weekly refresh of set social-share (OG) collage images (top cards + prices).
Schedule::command('catalog:set-share-images')->weeklyOn(1, '04:00')->withoutOverlapping();

// Amazon stock alerts: tweet when a watched ASIN is in stock at/below target.
// Ticks every 5 min; each alert self-throttles to its own check_interval_minutes,
// so this stays under the shared daily Oxylabs cap. Needs the Cloud scheduler.
// withoutOverlapping(10): if a run is killed mid-flight (e.g. a deploy), the
// mutex auto-expires in 10 min instead of the 24h default, so checks resume.
Schedule::command('stock:check-alerts')->everyFiveMinutes()->withoutOverlapping(10);
