import type { Holding, PortfolioSummary } from '@/types';

/** The least a row needs for the value/count half of a summary. */
type ValuedHolding = { market_value: number | null; quantity: number };

/**
 * Value and counts only — no cost basis, no P&L. This is what a public
 * collection page can total, and the split is deliberate: that payload carries
 * no cost data at all, so there is nothing for a public subtotal to leak even
 * by accident.
 */
export function summarizeValue(holdings: ValuedHolding[]): {
    total_value: number;
    item_count: number;
    card_count: number;
    valued_count: number;
} {
    const valued = holdings.filter((h) => h.market_value !== null);

    return {
        total_value: valued.reduce((n, h) => n + (h.market_value ?? 0), 0),
        item_count: holdings.length,
        card_count: holdings.reduce((n, h) => n + h.quantity, 0),
        valued_count: valued.length,
    };
}

/**
 * Total a set of holdings the same way the server's BuildPortfolio action does,
 * so a client-side subtotal (the holdings table's filtered view) is directly
 * comparable to the whole-collection figures rendered from the server prop.
 *
 * The one asymmetry worth preserving: `total_cost` covers EVERY holding, while
 * the gain subtracts only `cost_basis_valued` — the cost of holdings that
 * actually have a market value. A card whose priced state has no value would
 * otherwise drag the P&L down as pure loss. It does mean
 * `total_value - total_cost` won't equal `unrealized_gain` when something in
 * view is unpriced, which is the honest answer rather than a tidy one.
 */
export function summarize(
    holdings: Holding[],
    currency = 'USD',
): PortfolioSummary {
    const base = summarizeValue(holdings);

    const totalCost = holdings.reduce((n, h) => n + h.cost_basis, 0);
    const costOfValued = holdings
        .filter((h) => h.market_value !== null)
        .reduce((n, h) => n + h.cost_basis, 0);
    const unrealized = base.total_value - costOfValued;

    return {
        ...base,
        total_cost: totalCost,
        cost_basis_valued: costOfValued,
        unrealized_gain: unrealized,
        unrealized_pct:
            costOfValued > 0
                ? Math.round((unrealized / costOfValued) * 1000) / 10
                : null,
        currency,
    };
}
