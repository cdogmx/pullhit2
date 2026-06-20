# Spec: Value snapshots & price history

Status: building (June 2026). Owner: valuation seam (§7).

## Problem

`market_values` holds the *current* computed median per priced state, overwritten
on every recompute — there is no time dimension. So we can't chart a card's value
over time or a user's portfolio value over time. The card detail page has a
sparkline, but it's derived live from raw `sale_observations` (recent real sales),
not a stored series.

## Reality check (live DB, June 2026)

- ~1.39M `sale_observations`, but only ~21.6k are **real** (eBay/Oxylabs); the rest
  are **synthetic** seeds from reference prices (PriceCharting/TCGCSV).
- Real sales span ~153 distinct days. `market_values` covers ~47k cards.
- Conclusion: we cannot fabricate deep *real* history. The design is
  **snapshot-forward** (build a clean series from today) plus a **shallow backfill
  from real observations only**. Deep history is a separate, source-dependent effort.

## Data model

### `value_snapshots` — per card-state daily median (card-page charts)
```
id
catalog_item_id  -> catalog_items (cascade)
state_key        string   -- e.g. 'NM', 'SEALED'
median_cents     integer
n_sales          unsigned int
confidence       unsigned tinyint nullable
is_estimated     bool
captured_on      date
unique(catalog_item_id, state_key, captured_on)
index(catalog_item_id, state_key, captured_on)
```
We snapshot the **headline ungraded state** (`NM` for singles, `SEALED` for sealed)
of **eligible** cards only (see below) — one row per card per day.

### `portfolio_snapshots` — per user/day (dashboard chart)
```
id
user_id          -> users (cascade)
total_value_cents  integer
cost_basis_cents   integer
card_count       unsigned int
captured_on      date
unique(user_id, captured_on)
```
Tiny (#users × days). Directly answers "my portfolio over time" without
re-deriving holdings history.

## Eligibility (avoid bulk/common cards)

A card's headline ungraded value is snapshotted when **either**:

1. `is_estimated = false` (real signal) **and** `median >= valuation.snapshot.min_value`
   (default $3) **and** rarity NOT in `valuation.snapshot.skip_rarities`
   (default Common, Uncommon); **or**
2. the card is **owned** (any `collection_items`) or **wishlisted** (any
   `wishlist_items`) — always tracked regardless of value/rarity.

This focuses storage on higher-rarity, valued cards plus everything a user cares
about, and excludes the long tail of $0.10 commons.

## Capture — `valuation:snapshot` (daily)

- **value_snapshots**: a single `INSERT … SELECT` from `market_values` joined to
  `catalog_items`, filtered by the eligibility rule, for `state_key IN ('NM','SEALED')`
  and `grading_company_id IS NULL`. Idempotent on `captured_on` (re-run replaces the day).
- **portfolio_snapshots**: per user with collection items, via the existing
  `BuildPortfolio` action (reuses tested value/cost logic).
- Schedule: `Schedule::command('valuation:snapshot')->dailyAt('06:30')->withoutOverlapping()`.
- `--backfill`: seed `value_snapshots` from **real** (`is_synthetic = false`)
  `sale_observations` bucketed to daily medians (sparse, ~months). Never backfills
  from synthetic rows.

## Scale & retention

- Eligible set ≪ 47k (real-valued + owned/wishlisted), so daily growth is bounded.
- Future: a `valuation:compact-snapshots` step can collapse daily → weekly beyond
  ~180 days if needed. Not required at launch.

## Read paths

- **Card page**: `valueHistory` (the card's headline-state snapshot series) →
  value-over-time line. The existing live sale-observation sparkline stays as
  "recent real sales".
- **Dashboard / collection**: `portfolioHistory` (the user's `portfolio_snapshots`)
  → portfolio value over time.

Both reuse the dependency-free `Sparkline` (PricePoint `{t, price}`).

## Deep historical data — external source survey

| Source | Depth | Access | Cost | Notes |
|---|---|---|---|---|
| Our `sale_observations` (real) | ~5 mo, sparse | have it | free | backfill seed only |
| eBay Marketplace Insights API | 90 days | OAuth, gated | free-ish | hard 90-day cap |
| eBay Terapeak | 2–3 yr | Seller Hub UI only | free | not a public API |
| PriceCharting official API | current only | token (have) | paid | no history endpoint |
| PriceCharting chart data | years | scrape chart JSON | free | ToS risk; deepest aligned real history |
| tcgapi.dev / JustTCG / TCGAPIs | from Mar 2025 | API key | paid tiers | shallow-ish, growing |
| TCGplayer official API | current only | partner (closed) | — | no history |

Every programmatic path to *deep real* history is capped at 90 days (eBay APIs),
UI-only (Terapeak), current-only (PriceCharting/TCGplayer official), or ToS-risky
(scraping). Stance: **own the forward series via daily snapshots**; optionally
enrich with a paid third-party later; treat PriceCharting chart scraping as a
deliberate, ToS-reviewed decision, not a default.

## Phasing

1. `portfolio_snapshots` + command + dashboard chart (cheap, immediate payoff).
2. `value_snapshots` (eligible cards) + card-page chart + `--backfill`.
3. (Optional) paid third-party or ToS-reviewed PriceCharting importer for deep history.
