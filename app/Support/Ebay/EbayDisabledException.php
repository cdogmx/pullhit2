<?php

namespace App\Support\Ebay;

/**
 * Thrown when an eBay fetch is attempted while eBay fetching is switched off.
 *
 * Extends EbayBlockedException on purpose. Every caller already knows how to
 * handle "we could not read eBay" — log it, leave ebay_refreshed_at alone, keep
 * the existing comps — and being switched off wants exactly that treatment. A
 * new exception type would instead surface as a 500 on a card page for the one
 * caller that forgot to catch it.
 */
class EbayDisabledException extends EbayBlockedException {}
