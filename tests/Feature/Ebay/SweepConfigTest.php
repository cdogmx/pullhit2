<?php

use App\Models\ProductLine;

/**
 * The sweep searches are hand-written URLs in config, and a wrong one fails
 * quietly: the agent fetches a page, nothing matches, and the sweep looks like
 * it ran. These are the properties that make a sweep a sweep.
 */
test('every configured sweep asks for completed listings, a full page, and a known line', function () {
    $lines = ProductLine::pluck('slug')->all();

    foreach ((array) config('valuation.ebay.sweep.searches') as $search) {
        $label = $search['label'] ?? '(unlabelled)';

        expect($search)->toHaveKeys(['label', 'language', 'line', 'interval_minutes', 'url']);

        // Sold sweeps only. An active listing has no sold date, and the comp
        // classifier rejects a candidate without one — so a sweep pointed at a
        // live search would fetch a page and discard every row of it.
        expect(str_contains($search['url'], 'LH_Sold=1'))->toBeTrue();
        expect(str_contains($search['url'], 'LH_Complete=1'))->toBeTrue();

        // Without _ipg eBay serves a default-sized page, and the whole point of
        // a sweep is that one fetch returns hundreds of sales.
        expect(str_contains($search['url'], '_ipg='))->toBeTrue();

        expect($search['interval_minutes'])->toBeGreaterThanOrEqual(10);

        // The label's line is what a re-resolve filters the catalog by; an
        // unknown slug resolves to no id and the filter quietly does nothing.
        if ($lines !== []) {
            expect(in_array($search['line'], $lines, true))->toBeTrue();
        }
    }
});

test('sweep labels are unique', function () {
    // The enqueue command throttles per label and reads "last finished" by it;
    // two searches sharing one label would each suppress the other.
    $labels = array_column((array) config('valuation.ebay.sweep.searches'), 'label');

    expect($labels)->toHaveCount(count(array_unique($labels)));
});
