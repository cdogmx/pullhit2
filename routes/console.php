<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Valuation recompute seam (§7). Wire on Laravel Cloud's scheduler when ready —
// hot items hourly, the long tail daily. Kept off by default.
// Schedule::command('valuation:recompute')->hourly();
