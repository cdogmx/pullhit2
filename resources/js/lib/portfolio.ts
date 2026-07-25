import type { Holding, PortfolioSummary } from '@/types';

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
    const valued = holdings.filter((h) => h.market_value !== null);

    const totalValue = valued.reduce((n, h) => n + (h.market_value ?? 0), 0);
    const totalCost = holdings.reduce((n, h) => n + h.cost_basis, 0);
    const costOfValued = valued.reduce((n, h) => n + h.cost_basis, 0);
    const unrealized = totalValue - costOfValued;

    return {
        total_value: totalValue,
        total_cost: totalCost,
        cost_basis_valued: costOfValued,
        unrealized_gain: unrealized,
        unrealized_pct:
            costOfValued > 0
                ? Math.round((unrealized / costOfValued) * 1000) / 10
                : null,
        item_count: holdings.length,
        card_count: holdings.reduce((n, h) => n + h.quantity, 0),
        valued_count: valued.length,
        currency,
    };
}
