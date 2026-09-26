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

// Values built on comps that have since been deleted. --stale cannot see these:
// it looks for an observation NEWER than the value, and removing rows does not
// make the survivors newer, so a card keeps a price derived from sales it no
// longer holds. July's prune left 1,066 of them, 441 showing a price with no
// comps behind it at all. Cheap, and it only touches cards whose counts already
// disagree — so it does nothing on a healthy catalog.
Schedule::command('valuation:recompute --orphaned')->dailyAt('07:00')->withoutOverlapping();

// The catalog's health, measured against a source we do not compute: how far
// our raw prices sit from PriceCharting's. Read-only and cheap. Worth a standing
// slot because every pricing bug found so far was invisible from the inside —
// including one introduced in the anchor itself, which this caught on its first
// real run when the test suite could not.
Schedule::command('valuation:price-divergence')->dailyAt('07:30')->withoutOverlapping();

// AI re-read of the comps behind the cards that report flags, and ONLY those.
// The deterministic classifier is right on about 99.8% of comps and its misses
// have been rule-shaped — a grader brand absent from a list, a number form the
// gate could not read — each fixed once and pinned by a test. What a model adds
// is the judgement a regex cannot make: a reprint sharing its name, number and
// artwork with the original at a fortieth of the price.
//
// Suggest-only deliberately. Its output is worth more as a pattern to turn into
// a classifier rule than as a nightly delete, and nothing should remove a real
// sale on a model's say-so without someone reading it first.
Schedule::command('valuation:adjudicate-comps --cards=40')->dailyAt('08:00')->withoutOverlapping();

// Broad eBay sold-comp sweeps. Ticks often; each configured search self-throttles
// to its own interval (config valuation.ebay.sweep), so this stays under the
// daily Oxylabs cap. Needs Laravel Cloud's scheduler enabled to run.
Schedule::command('valuation:sweep-ebay')->everyTenMinutes()->withoutOverlapping(15);

// The same sweeps, for the browser agent. eBay serves completed listings only
// to a signed-in session, so when the server-side sweep above is switched off
// this is what actually runs them. Every five minutes only decides how often we
// LOOK: each search still honours its own interval_minutes, and a label already
// queued or in flight is never queued twice.
Schedule::command('ebay:enqueue-sweeps')->everyFiveMinutes()->withoutOverlapping(10);

// Intraday value readings for whatever set is currently featured. eBay dates a
// sold listing without a time, so an hourly SOLD price cannot be reconstructed
// at any cadence — what this records is our own estimate moving as sales are
// found, plus the asking prices, which really do change through the day.
//
// Every fifteen minutes is the resolution of the series. Nothing is written when
// no set is featured, which is most of the time.
Schedule::command('valuation:tick')->everyFifteenMinutes()->withoutOverlapping(14);

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
