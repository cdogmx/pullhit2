<?php

namespace App\Support\Ebay;

use RuntimeException;

/**
 * Thrown when an eBay fetch comes back parseable-as-empty but the page isn't a
 * genuine search-results page — i.e. an anti-bot interstitial, a captcha, or a
 * truncated body. Distinct from a legitimate zero-result search so callers can
 * retry (rather than caching a false "no comps") and surface it clearly.
 */
class EbayBlockedException extends RuntimeException {}
