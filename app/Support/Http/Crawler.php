<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * Is this request an automated client rather than a person?
 *
 * Card views have side effects meant for people: they bump popularity (which
 * drives refresh cadence) and queue eBay refreshes at the agent's top priority.
 * On 2026-11-20 GPTBot walked ~5,700 card pages in two hours and queued 3,000
 * scrapes ahead of every real reader. Crawlers still get the page — indexing
 * is the point — just not the side effects.
 *
 * Deliberately a short pattern list rather than a package: the honest crawlers
 * that cause this volume all announce themselves, and a miss costs one queued
 * scrape, not a broken page.
 */
class Crawler
{
    private const PATTERN = '/(?<!cu)bot\b|bot\/|crawl|spider|slurp|facebookexternalhit|embedly|preview|headless|python-|curl\/|wget|go-http|httpclient|okhttp|axios|scrapy|ahrefs|semrush|petal|bytespider|ia_archiver/i';

    public static function is(Request|string|null $request): bool
    {
        $agent = $request instanceof Request ? $request->userAgent() : $request;

        // No user agent at all is a script, never a browser.
        if ($agent === null || trim($agent) === '') {
            return true;
        }

        return (bool) preg_match(self::PATTERN, $agent);
    }
}
