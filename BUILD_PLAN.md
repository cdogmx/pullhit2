# Collectibles Platform — Build Plan (Cursor Brief)

> Working codename: **`tcg-platform`** (rename later). This document is the authoritative engineering brief. Build in the phase order below. Do **not** jump ahead — each phase has guardrails marked **🚫 Not yet**.

---

## Status snapshot — updated 2026-06-27

**Phases 0–4 are built and live** (CardFoo.com / pullhit.com). **Phase 5 (marketplace) is the next major build.** Substantial scope was added on top of the original brief — see **§14** (2026-06 base sprint), **§14.1** (community/giveaways, multi-game image backfill, broad eBay sweeps, homepage), and **§14.2** (the late-June sprint: **sealed product catalog + valuation**, a **4th game (Cyberpunk)**, **social graph/profiles**, AI-assisted sweep matching, shareable collection folders).

| Phase | Status |
|---|---|
| 0 — Foundations | ✅ Done (AppShell shared chrome, Sanctum, S3, Pest/CI; mobile **bottom-tab nav not built** — sidebar instead) |
| 1 — Core schema | ✅ Done (verticals → product_lines → sets → catalog_items + JSON facets + Vertical registry) |
| 2 — Mining / ingestion | ✅ Done — **PHP-native, not Python** (no `scrapers/`; see §6 note) |
| 3 — Valuation | ✅ Done (engine, sale_observations, market_values, confidence; eBay sold via Oxylabs; synthetic seeding) |
| 4 — Collections + Scanning | ✅ Done (collection + acquisition lots + portfolio; scan with language **and** edition/variant detection) |
| 5 — Marketplace | ⬜ **Not started — next major phase** |
| 6 — Mobile/PWA + API hardening | 🟡 Partial (`/api/v1` + AppShell exist; PWA install/offline + bottom-tab not built) |
| 7 — Second vertical (Sports) | ⬜ Not started (core stayed vertical-agnostic — architecture ready) |

**Catalog today (2026-06-27):** **4 games — Pokémon (180 sets, ~37.7k), One Piece (112 sets, ~11.9k, EN + JA), Disney Lorcana (14 sets, ~2.7k), Cyberpunk TCG (4 sets, 88)** = **310 sets / ~52.4k `catalog_items`** — of which **~49.9k singles + ~2.6k sealed products** (booster boxes/ETBs/packs/tins, imported with images + market prices, §14.2). Back to Base Set 1999; editions/variants modeled and PriceCharting-reconciled. **~98% of items have stored images** (S3). Valuation: ~69.7k `market_values`, **~28.7k real sold observations** (eBay sweeps + on-view + AI-recovered).

---

## 0. What we are building

A collection-tracking, valuation, and marketplace platform for **all collectible verticals** — TCG (Pokémon first), sports cards, and other collectibles — with:

- Accurate, confidence-scored market values for **sealed product, raw singles (by condition), and graded items (by grading service + grade)**.
- Scan-to-add collection management with strict **language / variant disambiguation**.
- A first-party **marketplace** where collectors list and sell their items.
- One codebase serving **web today and a native app later** (API-first).

### Guiding principles (these drive every decision)

1. **Multi-vertical from day one.** Pokémon ships first, but no TCG-specific assumption may leak into core tables. The value-bearing unit is a generic `catalog_item`. Verticals describe *themselves* via attribute definitions. Adding "Sports Cards" must require **zero migrations to core tables** — only new seed data, a source adapter, and an identifier strategy.
2. **API-first.** All business logic lives in framework-agnostic Action/Service classes. Web (Inertia) and the future app (JSON API) are two thin presentation layers over the same actions. Never put domain logic in a controller or a React component.
3. **Mobile-first.** Every screen is designed at 375px first. Persistent app-shell layout (shared header/footer), bottom tab nav on mobile, PWA-installable. Touch targets ≥ 44px.
4. **Values are distributions, not points.** Every price we show carries `{median, low, high, n, confidence, as_of}`. This is our core differentiator vs. TCGplayer/Collectr/PriceCharting (see `§7`).
5. **Data ingestion before features.** We cannot build valuation, scanning, or collections without a populated catalog. Phase 1–2 are catalog + data; resist building UI features first.

---

## 1. Stack & hosting

| Layer | Choice | Notes |
|---|---|---|
| Framework | **Laravel** (latest) + official **React starter kit** | Inertia v2 + React 19 + TypeScript + Tailwind v4 + shadcn/ui. Pin exact versions at install; commit the lockfiles. |
| DB | **MySQL 8** | Use JSON columns + **generated/virtual columns** for indexed facets (see `§4`). MySQL 8.0.13+ required for that pattern. |
| Hosting | **Laravel Cloud** | Configure queue worker + scheduler. Use Laravel Cloud's managed MySQL + object storage envs. |
| Object storage | **AWS S3** | Card/product images. CloudFront in front for delivery. See `§6` for bucket layout. |
| Auth | **Sanctum** | Session cookies for Inertia web; personal-access tokens for the future native app. Single guard, two token paths. |
| Queue/cache | Redis (Laravel Cloud add-on) | Mining ingestion, valuation recompute, image processing all run on queues. |
| Mining | **Python** (local, separate `/scrapers` workspace) | Pulls catalog + price observations, downloads/normalizes images, uploads to S3, emits manifests. Laravel ingests manifests. **Not deployed** — runs locally/cron on your machine or a small worker box. |
| Stats (optional, later) | Python valuation microservice | MVP valuation runs in PHP. Leave a clean seam to move the heavy stats to Python (you already run Laravel+Python in Chainify). |

---

## 2. Repository layout

Monorepo. Laravel app at root, Python tooling isolated.

```
tcg-platform/
├── app/
│   ├── Actions/               # ALL domain logic (invokable, testable, framework-agnostic)
│   │   ├── Catalog/
│   │   ├── Valuation/
│   │   ├── Collection/
│   │   ├── Scanning/
│   │   └── Marketplace/
│   ├── Models/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Web/           # return Inertia responses; call Actions
│   │   │   └── Api/V1/        # return JSON Resources; call the SAME Actions
│   │   ├── Resources/         # API JSON resources
│   │   └── Requests/          # FormRequests shared by web + api where possible
│   ├── Support/
│   │   ├── Verticals/         # vertical registry + attribute schema definitions
│   │   ├── Mining/            # SourceAdapter contract + ingestion services
│   │   ├── Scanning/          # IdentifierStrategy contract + per-vertical strategies
│   │   └── Valuation/         # engine (median/MAD/EWMA/confidence)
│   └── Console/Commands/      # artisan importers, recompute jobs
├── resources/js/
│   ├── layouts/               # AppShell (shared header/footer) — see §10
│   ├── pages/                 # Inertia pages: marketing/, dashboard/, catalog/, collection/, marketplace/
│   ├── components/            # shadcn-based, reused web + (eventually) RN-friendly logic in hooks/
│   └── lib/                   # api client, formatters (price/confidence), facet renderers
├── database/migrations/
├── scrapers/                  # PYTHON — isolated, own venv, own README
│   ├── adapters/              # one module per source: tcgcsv.py, pokemontcgio.py, scryfall.py, ...
│   ├── images/                # download + webp derivative generation + S3 upload
│   ├── normalize/             # map raw source rows -> canonical catalog manifest
│   ├── out/                   # generated manifests (gitignored)
│   └── pyproject.toml
└── tests/                     # Pest
```

**Hard rule for Cursor:** controllers are thin. If a controller method is more than ~10 lines or contains business rules, extract to an Action in `app/Actions/`. Both `Web` and `Api/V1` controllers call the identical Action.

---

## 3. Architecture seams (the three extension points)

Adding a new vertical or data source must only touch these three contracts. Everything else is generic.

1. **`Vertical` registry** (`app/Support/Verticals/`) — declares each vertical's attribute schema (what facets a catalog item has, their types, which are searchable/required, display order). Drives dynamic forms, faceted search, validation, and the scan-confirm UI.
2. **`SourceAdapter`** (`app/Support/Mining/Contracts/SourceAdapter.php`) — normalizes a raw source (TCGCSV, pokemontcg.io, Scryfall, a Python scrape manifest) into canonical catalog + observation rows. Python side has a mirror contract.
3. **`IdentifierStrategy`** (`app/Support/Scanning/Contracts/IdentifierStrategy.php`) — given image(s)/OCR text, returns ranked `catalog_item` candidates for that vertical. TCG strategy ≠ sports strategy.

If a change to support sports cards requires editing `catalog_items`, `sale_observations`, `market_values`, `collection_items`, or `listings` — **stop, it's wrong.**

---

## 4. Data model (core — vertical-agnostic)

### 4.1 Catalog hierarchy

```
verticals (tcg, sports, other)
  └─ product_lines        # TCG: Pokémon, MTG, One Piece | Sports: Basketball, Football
       └─ sets            # a release/product/set; language-specific for TCG
            └─ catalog_items   # THE value-bearing unit: a single, a sealed product, etc.
```

**`verticals`**
| col | type | notes |
|---|---|---|
| id, slug, name | | `tcg`, `sports`, `other` |
| config | json | default display, scan strategy key, etc. |

**`product_lines`**
| col | type | notes |
|---|---|---|
| id, vertical_id, slug, name | | |
| metadata | json | |

**`sets`**
| col | type | notes |
|---|---|---|
| id, product_line_id, slug, name, code | | |
| language | string null | **generated/indexed**; TCG sets are language-specific (EN "151" ≠ JP "sv2a") |
| series / set_family | string null | groups cross-language equivalents |
| released_at | date null | |
| external_ids | json | `{tcgplayer_group_id, ptcgio_set_id, scryfall_set, ...}` for dedup/joins |

**`catalog_items`** — the heart. Generic across all verticals.
| col | type | notes |
|---|---|---|
| id | | |
| vertical_id, product_line_id, set_id | FK | set_id nullable for some "other" items |
| item_type | enum | `single`, `sealed`, `lot`, `other` |
| name | string | display name |
| number | string null | collector/card number (**generated+indexed** where in attributes) |
| **attributes** | **json** | all vertical-specific facets live here (see 4.2) |
| language | string null | **generated column** from `attributes->>'$.language'`, indexed |
| identity_hash | char(64) unique | sha256 of (vertical, product_line, set, canonical facets) — dedup key |
| primary_image_path | string null | S3 key |
| external_ids | json | `{tcgplayer_product_id, ptcgio_id, scryfall_id, ...}` |
| timestamps | | |

> **Why JSON + generated columns:** sports and TCG don't share facets, and EAV is painful to query. MySQL 8 generated columns let us index the few high-value searchable facets (language, rarity, number, player, year, parallel, serial) while keeping the rest flexible. Add a generated column + index per facet a vertical actually needs to filter on.

### 4.2 Attribute facets by vertical (illustrative — defined in the Vertical registry, not hardcoded in tables)

- **TCG single:** `rarity, variant (holo/reverse/1st-ed/unlimited), finish, language, illustrator, hp/type (optional)`
- **TCG sealed:** `sealed_type (booster_box, ETB, booster_pack, collection, bundle), language, pack_count`
- **Sports single:** `player, team, year, parallel (base/prizm/refractor/…), serial_numbered (/99,/10,1/1), is_rookie, is_auto, is_relic, subset`
- **Other:** freeform per type.

Each facet is declared with `{key, label, type, searchable, required, indexed, options?}`. The React forms, filters, and scan-confirm screen render dynamically from this — **no per-vertical UI hardcoding.**

### 4.3 Grading & condition (generic, applies to a *physical copy*, not the catalog item)

A `catalog_item` is the abstract raw card/product. Grading is a **state of an instance** (an owned copy, an observed sale, a listing). So grade lives on `collection_items`, `sale_observations`, and `listings`, never on `catalog_items`.

**`grading_companies`**: `id, slug (psa,bgs,cgc,sgc,tag,ace,...), name, scale_max (10), supports_half_grades, supports_subgrades, supports_pristine_black_label`.

**Condition (raw):** enum `NM, LP, MP, HP, DMG` for cards; `SEALED` for sealed product (matches TCGplayer/JustTCG scale for clean joins).

**Grade representation (on instances):** `grading_company_id` (null = raw), `grade` (decimal, e.g. 9, 9.5, 10), `subgrades` (json, e.g. BGS centering/corners/edges/surface), `cert_number`, `grade_label` (e.g. "Pristine", "Black Label").

### 4.4 Pricing data layer

**`sale_observations`** — raw, append-only market signal (mining + ongoing). The valuation engine consumes this.
| col | type | notes |
|---|---|---|
| id | | |
| catalog_item_id | FK | |
| condition | enum null | for raw |
| grading_company_id, grade, grade_label | | null = raw |
| venue | enum | `tcgplayer, ebay, whatnot, own_marketplace, other` |
| price, currency | | |
| observed_at | datetime | actual sale time |
| source_listing_id | string null | dedup across re-pulls |
| is_outlier | bool | set by engine (MAD/Hampel) — never deleted |
| raw | json | original payload for audit |

Indexes: `(catalog_item_id, condition, grading_company_id, grade, observed_at)`.

**`market_values`** — computed snapshot the app/API reads (recomputed on a schedule). One row per priced state.
| col | type | notes |
|---|---|---|
| catalog_item_id, condition, grading_company_id, grade | | the "priced state" key |
| median, p25, p75, low, high | decimal | distribution, not a point |
| n_sales | int | sample size in window |
| confidence | decimal 0–1 | function of n, recency, dispersion (see §7) |
| half_life_days | int | velocity-aware window actually used |
| trend_30d, trend_90d | decimal null | % change |
| computed_at | datetime | |

> Reads always come from `market_values`. Never compute on the fly in a request.

### 4.5 Collection & marketplace (generic)

**`collection_items`**: `user_id, catalog_item_id, condition, grading_company_id, grade, subgrades, cert_number, quantity, is_for_sale, notes, custom_photos(json), created_at`.

**`acquisition_lots`** (tax-lot accounting — you flagged this as a product edge): `collection_item_id, quantity, unit_cost, acquired_at, source, fees`. Cost basis = lots, not a single field. Realized gain computed against lots on sale (FIFO/LIFO/spec-ID selectable).

**`listings`**: `user_id, collection_item_id, catalog_item_id (denorm), price, quantity, condition/grade snapshot, shipping_price, status (draft/active/sold/cancelled), photos`.
**`orders`, `order_items`, `payments`**: standard. Abstract the payment provider behind a `PaymentGateway` interface (you use PayPal Marketplace elsewhere; Stripe Connect is the likely fit for a card marketplace — keep it swappable).

---

## 5. Data sources & legal posture

**Prefer official/bulk APIs. Scrape only as a fallback, per-source, with backoff and respect for ToS.**

| Source | Use for | Vertical | Access | Notes |
|---|---|---|---|---|
| **TCGCSV** (tcgcsv.com) | Catalog (categories→groups→products incl. **sealed + singles**) + baseline price | TCG (multi-game) | Free public JSON/CSV entrypoint to TCGplayer's API | Primary catalog + sealed source. categoryId 3 = Pokémon. Back them on Patreon. |
| **pokemontcg.io** | Pokémon card metadata + **images** | Pokémon | Free API key | Primary image source for Pokémon singles. |
| **Scryfall** | MTG metadata + images (bulk data) | MTG | Free bulk download | Best-in-class when MTG vertical activates. |
| **JustTCG** | Pricing + **price history** (7/30/90/180/1y), delta sync, queryable by TCGplayer SKU / Scryfall / MTGJSON | TCG | Commercial REST API | Strong observation feed; supports `Sealed` + raw conditions. Evaluate for ongoing price pulls. |
| **TCGplayer official API** | Catalog + Hi/Mid/Low + lowest vendors | TCG | Partner-gated (bearer token) | Apply early; long approval. Don't block on it. |
| **eBay Marketplace Insights** | Real sold comps | All | **Restricted / approved partners only** (Finding API deprecated) | The clean sold-comp firehose is fenced off and eBay's June 2025 license bars feeding restricted data into 3rd-party genAI. Architect so eBay is *additive*, never load-bearing. |
| Sports catalog | Player/set/parallel catalog + images | Sports | No clean free equivalent | Handle when sports vertical activates: likely a commercial source + targeted Python scrape + manual curation. Flagged, not built now. |

**Posture:** store `raw` payloads for audit; record `source` and `observed_at` on every observation; honor robots/ToS in Python adapters; throttle; never feed restricted-API data into model training.

---

## 6. Data mining toolkit (Python, local — Phase 2)

> **⚠️ As-built (2026-06-14): this section was NOT followed as written.** Ingestion is **PHP-native**, not a Python `scrapers/` workspace. Catalog/images come from HTTP clients + artisan commands: `App\Support\Catalog\PokemonTcgClient` (pokemontcg.io), `TcgcsvClient` (Japanese via TCGCSV), `App\Support\Pricecharting\CsvClient` (Legendary price guide), and `CardImageStore` for S3 (stores source images directly; no WebP derivative pipeline). The manifest-based `catalog:import` / `observations:import` design below was superseded by direct importers (`catalog:import-set`, `catalog:import-curated`, `catalog:import-missing-en`, `catalog:import-jp`, `catalog:pricecharting-import`, `valuation:refresh-ebay`). eBay sold comps come from Oxylabs in PHP. The §5 "mining before features" principle held regardless — the catalog shipped before the UI. The Python design is retained below as the reference for if/when a heavier scraping tier is needed.

Runs locally, populates catalog, uploads images, emits manifests; Laravel ingests.

### 6.1 S3 bucket layout (organized, deterministic)

```
s3://<bucket>/
  <vertical>/<product_line_slug>/<set_code>/
    items/<catalog_item_id_or_identityhash>/
      front.webp   front@2x.webp   front-thumb.webp
      back.webp    back@2x.webp     back-thumb.webp
    sealed/<item_slug>/
      0.webp  0@2x.webp  0-thumb.webp
```
Python generates **all derivatives** (thumb ~150px, standard ~750px, @2x) as WebP before upload to keep Laravel/S3 costs and runtime work low.

### 6.2 Python adapter contract

Each adapter (`tcgcsv.py`, `pokemontcgio.py`, `scryfall.py`, future sports) implements:
- `fetch()` → raw rows
- `normalize(raw)` → canonical manifest rows matching the catalog schema (vertical, product_line, set, item_type, name, number, attributes{}, external_ids{}, image_urls[])
- emits `scrapers/out/<source>-<vertical>-<timestamp>.jsonl` + an images manifest

### 6.3 Laravel ingestion

`php artisan catalog:import {manifest}`:
- upserts `verticals/product_lines/sets/catalog_items` by `external_ids` then `identity_hash`
- verifies referenced S3 keys exist, sets `primary_image_path`
- idempotent (safe to re-run); logs created/updated/skipped counts
- `php artisan observations:import {manifest}` for price rows → `sale_observations`

**🚫 Not yet:** real-time scraping infra, distributed crawlers, scraping eBay sold. Local batch only.

---

## 7. Valuation engine (the differentiator — Phase 3)

Carry forward the methodology from the platform analysis. Implement in `app/Support/Valuation/` as pure, unit-tested services. MVP in PHP; clean seam to a Python stats service later.

Pipeline, per priced state `(catalog_item, condition, grading_company, grade)`:

1. **Pull observations** within a max lookback (e.g. 365d), per venue.
2. **Outlier rejection:** robust filter (median + MAD / Hampel), not mean ± stddev. Flag `is_outlier`, don't delete.
3. **Venue normalization:** apply per-venue bias priors (eBay "Sold For" can be inflated vs. actual paid; TCGplayer consolidates languages). Adjust before blending.
4. **Velocity-aware recency weighting:** EWMA with a **half-life that scales to sales velocity** — liquid items get a short half-life (trust recent), illiquid items a long one **plus a confidence penalty.**
5. **Output a distribution:** `{median, p25, p75, low, high, n_sales}` — never a single number.
6. **Confidence score (0–1):** increases with `n_sales` and recency, decreases with dispersion (IQR/median) and staleness of newest sale. Surface it in the UI ("$3.20 ±$1.10 · 6 sales · 4d ago · High confidence").
7. **Write `market_values`.** Recompute on schedule (hot items hourly, cold items daily).

This directly fixes the failure modes of the incumbents: thin-market overvaluation (Collectr), stale frozen prices (TCGplayer when no sales), and eBay's offer/sold inflation (PriceCharting).

**🚫 Not yet:** ML price prediction, cross-card comp modeling. Robust stats first.

---

## 8. Scanning & language/variant disambiguation (Phase 4)

Exposed as an **API endpoint** (`POST /api/v1/scan`) so the future native app reuses it verbatim.

Flow: capture image(s) → identify → return ranked `catalog_item` candidates with confidence → **user confirms** before anything is added.

**Vertical-aware `IdentifierStrategy`:**
- **TCG (Pokémon):** the reliable key is **set symbol + collector number + name**, and crucially the **script/language detection** (Latin vs Japanese kana/kanji vs Korean hangul vs Chinese) — narrows `sets.language` *before* matching so EN/JP/KR equivalents never collide. Same artwork in different languages must resolve to different `catalog_items`. Use OCR for number/name + script detection; optionally a vision LLM (your GeekyKeke approach) for hard cases.
- **Sports:** player + year + set + parallel + serial; OCR + logo/parallel cues. (Build when sports activates.)

**Hard requirement:** language/variant is part of the match key, never inferred-away. If confidence is low or multiple languages plausible, **ask the user** (render candidates with language + set + number clearly). Same rule for **sealed** products (EN booster box ≠ JP booster box — different `catalog_items`).

**🚫 Not yet:** auto-grading from scan. Identification only; grade is user-entered or from cert lookup.

---

## 9. Collections & tax lots (Phase 4)

- Add via scan or manual search; pick condition/grade/grading company/cert.
- Collection value = sum of matching `market_values` (respecting each item's condition+grade state) with a blended confidence indicator.
- **Acquisition lots** track cost basis per acquisition; realized/unrealized P&L; FIFO/LIFO/spec-ID. This is the fintech edge — surface portfolio analytics (cost vs. market, gainers/decliners, allocation by set/vertical) like a brokerage.
- Mobile: fast add flow, offline-tolerant queue for scans.

---

## 10. Shared layout, mobile, future app (Phase 0 + ongoing)

- **`AppShell` persistent layout** (Inertia persistent layout) wraps **both marketing and dashboard** pages → identical header/footer everywhere, no re-render/flash on navigation. Nav items are auth-aware (logged-out marketing CTAs vs. logged-in dashboard links) but the chrome is one component.
- **Mobile:** bottom tab bar (Home/Search/Scan/Collection/Marketplace) on small screens; header collapses; everything thumb-reachable.
- **PWA:** installable, app icons, offline shell. Stepping stone to the native app.
- **Future native app:** because all logic is in Actions behind `/api/v1`, the app is a new client over the same API + Sanctum tokens. Keep React data-fetching logic in framework-agnostic hooks/`lib` where reasonable to ease an eventual React Native/Expo port.

---

## 11. Phase plan (build in this order)

| Phase | Deliverable | Done when |
|---|---|---|
| ✅ **0 — Foundations** | Install starter kit; `AppShell` shared header/footer (marketing + dashboard); design tokens; Sanctum (session + token); S3 disk; queue/scheduler on Laravel Cloud; CI + Pest. | A marketing page and a dummy auth dashboard share one header/footer; mobile bottom nav renders; `/api/v1/ping` works with a token. |
| ✅ **1 — Core schema** | Migrations + models for verticals, product_lines, sets, catalog_items (JSON+generated cols), grading_companies, conditions; the **Vertical registry** + Pokémon attribute schema. | Can seed Pokémon vertical + a set + items by hand; faceted attributes validate against the registry. |
| ✅ **2 — Mining** *(PHP, not Python)* | Catalog + image ingestion. **Built PHP-native** — `PokemonTcgClient`/`TcgcsvClient`/PriceCharting importers + `CardImageStore` to S3, via artisan (`catalog:import-set`, `catalog:import-missing-en`, `catalog:pricecharting-import`). No `scrapers/`; see §6 note. | ✅ Pokémon catalog (singles + sealed) + images populated; importers idempotent. |
| ✅ **3 — Valuation** | `sale_observations` ingestion; engine (MAD outliers, venue priors, velocity EWMA, confidence); `market_values` + recompute jobs; price display component with confidence. | A Pokémon single shows median/IQR/n/confidence/trend; thin-market items show low confidence, not inflated values. |
| ✅ **4 — Collections + Scanning** | Collection CRUD, acquisition lots, portfolio analytics; `POST /api/v1/scan` + Pokémon `IdentifierStrategy` with language **and edition/variant** detection; scan-confirm UI. | ✅ Done — plus public collections, **wishlists** (target-price alerts), and edition-aware scan ranking (§14). |
| ⬜ **5 — Marketplace** *(next)* | Listings from collection, search/browse, cart, orders, payments (gateway interface), seller payouts. | ⬜ Not started — no `listings/orders/payments` tables yet. The intended differentiator (first-party sold data → wash-trade detection). |
| 🟡 **6 — Mobile/PWA polish + API hardening** | PWA install, offline scan queue, rate limits, API versioning discipline, docs. | 🟡 Partial — `/api/v1` + responsive AppShell exist; **PWA install/offline + mobile bottom-tab nav not built.** |
| ⬜ **7 — Second vertical (Sports)** | New seed + attribute schema + source adapter + identifier strategy **only**. | ⬜ Not started. Core stayed vertical-agnostic (edition/variant were added as *facets*, not core columns) — the architecture is still ready for this test. |

---

## 12. Conventions for Cursor

- **Thin controllers; logic in `app/Actions/`.** Web + API controllers call identical Actions.
- TypeScript everywhere on the front end; no `any`. Tailwind v4 + shadcn; no bespoke CSS frameworks.
- Money as integer minor units (cents) in DB; format at the edge. Always store currency.
- Every price the UI renders comes from `market_values` and shows confidence — **never** a bare number.
- Pest tests for every Action; factory + seeder per vertical.
- Migrations are additive and reversible; never edit a shipped migration.
- Idempotent importers; log counts; safe to re-run.
- Respect source ToS in Python; throttle + backoff; persist `raw` payloads.

## 13. Guardrails — do NOT do (until its phase)

- 🚫 No TCG-specific columns on core tables (no `card_number`, `rarity`, `language` as first-class columns on `catalog_items` — they're facets; `language` is the one allowed *generated* column).
- 🚫 No business logic in controllers or React components.
- 🚫 No on-the-fly price computation in requests.
- 🚫 No eBay sold-comp scraping as a dependency; treat eBay as additive if/when partner access lands.
- 🚫 No ML valuation, auto-grading, or real-time crawler infra in MVP.
- 🚫 No second vertical work before Phase 7 — but never write code that would block it.

---

## 14. Built beyond the original brief (as of 2026-06-14)

Shipped on top of Phases 0–4, roughly by impact. All of this respects §13 (no TCG columns on core tables; edition/variant are *facets* in `attributes`; logic in Actions; values from `market_values`).

- **Smart browse hierarchy** — brand → series → set → subset → card drill-down (not a flat catalog dump), SEO landings (`/browse/{line}`, `/browse/{line}/{set}`) + `sitemap.xml`, relevance-ranked tokenized search (name / number / set / edition), and sort by price / 30-day % change.
- **PriceCharting reconciliation + editions** *(largest addition).* Models card **edition** (`unlimited`/`shadowless`/`first_edition`) and error/promo **finish** tags as variant-defining facets. Ingests the PriceCharting Legendary price-guide CSV → `pricecharting_products`, diffs vs. catalog (`catalog:reconcile`), auto-applies high-confidence changes (~32k across 135 sets), queues the rest for review (`reconciliation_changes`, admin `/admin/reconcile`). Display names disambiguate printings ("Charizard (1st Edition)"); search, eBay lookups, and scanning are all edition-aware. Daily import + weekly reconcile scheduled (`routes/console.php`).
- **Wishlists** — heart-toggle on browse + card page, a `/wishlist` page with per-card **target prices**, opt-in **public wishlists** (`/wishlist/{username}`), and **target-price email alerts** (`wishlist:check-targets`, fires once per crossing, daily).
- **Public sharing** — opt-in public **collections** (`/collection/{username}`) and wishlists; cost basis / targets stay private.
- **Admin area** — brands/sets/cards editors (logo + description + S3 image upload), sealed-product management, set import/resync, missing-card reports, the reconciliation review queue.
- **eBay + affiliate** — Browse API active listings + EPN / TCGplayer affiliate "Shop on eBay" links (printing-aware); Oxylabs for sold comps with an edition/variant-gated classifier.
- **Billing scaffold** — Dodo Payments (merchant of record), membership tiers, scan quota.
- **Marketing/brand** — CardFoo brand + theme, beta notices, default OG share image, terms/privacy.

## 14.1 Sprint — 2026-06 (community, multi-game catalog, live pricing, homepage)

Layered on §14, same guardrails (§13). Roughly by impact:

- **Broad eBay sold-comp sweeps** *(biggest pricing addition).* Inverts the per-card pull: one paid Oxylabs query (e.g. "pokemon psa 10" sold) returns ~240 listings that `EbayTitleResolver` matches **back** to catalog cards by collector number + identity, classifies with the same guardrails, and ingests as `sale_observations` → real values. `valuation:sweep-ebay` runs config-driven graded searches, each self-throttled under the shared daily Oxylabs cap (scheduled every 10 min in `routes/console.php`). Admin **eBay-sweep quality view** (`/admin/ebay-sweep`) shows applied sales vs. logged misses (by reason), with **reject / reassign** actions that persist per-listing **overrides** (`ebay_sweep_overrides`) so a correction sticks across every future re-pull. Classifier now also rejects **multi-card set/bundle** listings (the "collection series" inflation fix).
- **Multi-game image backfill** — pushed image coverage to ~98%. Sources by game/language: pokemontcg.io secret-rare gaps and Japanese Pokémon from **TCGCSV** (TCGplayer mirror, cat 3 / 85) via `catalog:backfill-tcg-images`; **One Piece (EN + JA, ~10k images)** from the official card-list CDN via `catalog:backfill-op-images`. All store our own S3 copy (`CardImageStore`); idempotent, image-less rows only. (Diagnostic lesson: high row counts are legit printing variants — backfill, don't delete/re-import.)
- **Community rankings & giveaways** — append-only `contributions` points ledger + denormalized `users.contribution_points`, config-driven points & 6-level ladder (`config/community.php`). Earn points for approved edit suggestions and **new "report missing card/set"** submissions (`card_reports`, admin review at `/admin/card-reports`). Public **`/rankings`** leaderboard (all-time + monthly), **`/contribute`** flow, and "monthly points = giveaway entries" framing (draw mechanics deferred). *Advances the competitor-strategy "loud, manipulation-resistant community" wedge.*
- **Auth email hardening** — enforced **email verification** (`MustVerifyEmail`) so new signups confirm; **welcome email** on the `Verified` event; existing users grandfathered via a backfill migration. Root-caused why no mail sent: the Postmark transport needs **`symfony/http-client`** (was missing) — now installed.
- **Admin "Get values"** — synchronous on-card eBay refresh button, **always available** (even when a card has no values yet); honours the daily cap; returns the comp count.
- **Homepage remodel** — cached `HomeController` feeds the landing page under the hero: brand shortcuts, **trending cards**, **biggest movers** (real 30-day swings), **recently-updated prices** (the sweep pulse), **popular sets** (by card views), and a **points/giveaways explainer**.

## 14.2 Sprint — late 2026-06 (sealed product, 4th game, social, sweep accuracy)

Layered on §14/§14.1, same guardrails (§13). Roughly by impact:

- **Sealed product catalog + valuation** *(biggest addition).* Bulk-imported ~2,600 sealed products (booster boxes, ETBs, packs, tins, blisters, build-&-battle, cases) with images + TCGplayer market prices from **TCGCSV**: per-set `catalog:import-sealed {setId} {groupId}` and catalog-wide `catalog:import-sealed-all` (auto-matches every set to its TCGplayer group by name — conservative, skips ambiguous base-era/promo names; 226/306 sets matched). A **sealed-aware eBay comp classifier** prevents variant cross-contamination: a comp must agree with the product's **Case / Pokémon-Center / Plus** status and sealed type, rejecting the Case (6–10×) and PC-exclusive listings that were inflating regular SKUs. `Code Card -` products excluded; sealed comps live in the `SEALED` state bucket.
- **Cyberpunk TCG — 4th game.** Added via the **Netdeck.gg API** (powers cyberpunktcg.com — the site itself is JS-paginated with no public API). `catalog:import-cyberpunk` pulls all retail sets (88 cards / 4 sets) with images; `power`/`ram`/`faction` added to the tcg vertical schema. Proves the multi-game seam again.
- **Social graph + profiles.** Public user **profile pages** with avatars, a **follow graph** (followers/following) + **following feed**, and the community **giveaway draw** (weighted winner — the §14.1 deferred piece). **Social login** (Facebook live, Google ready) via Socialite + one-time username picker.
- **AI-assisted sweep matching + accuracy.** `valuation:ai-match-misses` batches unplaceable sweep misses through one Anthropic call (cheap title-text, no image) → structured identity → `CandidateMatcher` → auto-apply confident / best-guess the rest (recovered ~360 sales). `EbayTitleResolver` now parses Lorcana/promo `#NNN`; classifier fixes (graded comps bypass the raw band; Pokémon HP-stat ≠ Heavily Played; Beckett/`PSA-10`/`GEM MINT 10`). Multi-card "set" listings rejected via same-set sibling-name detection; `valuation:prune-bad-comps` cleaned ~520 inflated comps. Sweep admin (`/admin/ebay-sweep`) gained assign/reassign **with photos + best-guess**, a reject button, and non-match reasons.
- **Collections polish.** Shareable **folders** with independent public/private links (`/collection/{user}/{set}/folder/{slug}`), date-added (newest/oldest) sort, and tier-gated add-to-collection / add-to-wishlist pickers.
- **Browse filters** now show only options present in the current vertical/line/set scope (a lone `normal` variant is hidden).
- **Admin** per-user **detail page** — sessions/IP + device, scan history with detections, profile/collection/wishlist links, billing — linked from the roster.
- **Ops/auth** — Postmark transactional email (welcome / verify-on-signup / password reset), payment receipts, cancel-subscription confirm modal, Dodo live-mode fix. **Root-caused a silent outage:** the Laravel Cloud **scheduler was off**, so eBay sweeps and daily snapshots weren't running — now enabled.

### Updated next focus
1. **Phase 5 — Marketplace** *(the strategic differentiator).* First-party listings/orders/payments → first-party **sold data** → the wash-trade-detection wedge, now well-seeded by the transparency/community/sweep work. Still no `listings/orders/payments` tables.
2. **Proactive sealed comp coverage.** Sealed only gets real eBay comps on page-view (the sweep is collector-number-based, so it skips all sealed). Build a **name-based sealed sweep** so the ~2,600 sealed SKUs get real values without a visit — now safe to do given the sealed-aware classifier.
3. **Phase 6 — PWA + mobile bottom-tab nav** (the brief's mobile-first goal is only partly met).
4. **Early Adopter product** — $250 one-time, Guru benefits + badge + 500 monthly giveaway entries (Dodo product id staged in env; build deferred).
5. **Long-tail cleanup** — 80 sealed-unmatched sets (manual TCGplayer-group override map), sweep `no_number` misses, and the reconciliation backlog (graded re-seed, JP editions).

---

### First task for Cursor *(historical — Phase 0, completed)*

Start **Phase 0** only: scaffold the Laravel React starter kit, build the `AppShell` persistent layout shared across a sample marketing page and a sample authenticated dashboard page (identical header/footer, mobile bottom nav), wire Sanctum for both session (web) and token (api) auth, configure the S3 disk and queue/scheduler for Laravel Cloud, and add a trivial `/api/v1/ping`. Stop and report before touching the schema.


tailwind/shadcn style

@import "tailwindcss";

@custom-variant dark (&:is(.dark *));

:root {
  --background: oklch(0.9900 0 0);
  --foreground: oklch(0 0 0);
  --card: oklch(1 0 0);
  --card-foreground: oklch(0.3730 0.0340 259.7330);
  --popover: oklch(0.9900 0 0);
  --popover-foreground: oklch(0.3730 0.0340 259.7330);
  --primary: oklch(0.3730 0.0340 259.7330);
  --primary-foreground: oklch(1 0 0);
  --secondary: oklch(0.9400 0 0);
  --secondary-foreground: oklch(0.3730 0.0340 259.7330);
  --muted: oklch(0.9700 0 0);
  --muted-foreground: oklch(0.4400 0 0);
  --accent: oklch(0.9400 0 0);
  --accent-foreground: oklch(0.3730 0.0340 259.7330);
  --destructive: oklch(0.6300 0.1900 23.0300);
  --destructive-foreground: oklch(1 0 0);
  --border: oklch(0.9200 0 0);
  --input: oklch(0.9400 0 0);
  --ring: oklch(0.3730 0.0340 259.7330);
  --chart-1: oklch(0.6960 0.1700 162.4800);
  --chart-2: oklch(0.6460 0.2220 41.1160);
  --chart-3: oklch(0.7200 0 0);
  --chart-4: oklch(0.9200 0 0);
  --chart-5: oklch(0.5600 0 0);
  --sidebar: oklch(0.9900 0 0);
  --sidebar-foreground: oklch(0.3730 0.0340 259.7330);
  --sidebar-primary: oklch(0.3730 0.0340 259.7330);
  --sidebar-primary-foreground: oklch(1 0 0);
  --sidebar-accent: oklch(0.9400 0 0);
  --sidebar-accent-foreground: oklch(0.3730 0.0340 259.7330);
  --sidebar-border: oklch(0.9400 0 0);
  --sidebar-ring: oklch(0.3730 0.0340 259.7330);
  --font-sans: Inter, ui-sans-serif, sans-serif, system-ui;
  --font-serif: Lora, ui-serif, serif;
  --font-mono: Geist Mono, monospace;
  --radius: 0.5rem;
  --shadow-x: 0px;
  --shadow-y: 1px;
  --shadow-blur: 0px;
  --shadow-spread: -50px;
  --shadow-opacity: 0;
  --shadow-color: hsl(0 0% 0%);
  --shadow-2xs: 0px 1px 0px -50px hsl(0 0% 0% / 0.00);
  --shadow-xs: 0px 1px 0px -50px hsl(0 0% 0% / 0.00);
  --shadow-sm: 0px 1px 0px -50px hsl(0 0% 0% / 0.00), 0px 1px 2px -51px hsl(0 0% 0% / 0.00);
  --shadow: 0px 1px 0px -50px hsl(0 0% 0% / 0.00), 0px 1px 2px -51px hsl(0 0% 0% / 0.00);
  --shadow-md: 0px 1px 0px -50px hsl(0 0% 0% / 0.00), 0px 2px 4px -51px hsl(0 0% 0% / 0.00);
  --shadow-lg: 0px 1px 0px -50px hsl(0 0% 0% / 0.00), 0px 4px 6px -51px hsl(0 0% 0% / 0.00);
  --shadow-xl: 0px 1px 0px -50px hsl(0 0% 0% / 0.00), 0px 8px 10px -51px hsl(0 0% 0% / 0.00);
  --shadow-2xl: 0px 1px 0px -50px hsl(0 0% 0% / 0.00);
  --tracking-normal: 0em;
  --spacing: 0.25rem;
}

.dark {
  --background: oklch(0 0 0);
  --foreground: oklch(1 0 0);
  --card: oklch(0.1400 0 0);
  --card-foreground: oklch(1 0 0);
  --popover: oklch(0.1800 0 0);
  --popover-foreground: oklch(1 0 0);
  --primary: oklch(1 0 0);
  --primary-foreground: oklch(0 0 0);
  --secondary: oklch(0.2500 0 0);
  --secondary-foreground: oklch(1 0 0);
  --muted: oklch(0.2300 0 0);
  --muted-foreground: oklch(0.7200 0 0);
  --accent: oklch(0.3200 0 0);
  --accent-foreground: oklch(1 0 0);
  --destructive: oklch(0.6900 0.2000 23.9100);
  --destructive-foreground: oklch(0 0 0);
  --border: oklch(0.2600 0 0);
  --input: oklch(0.3200 0 0);
  --ring: oklch(0.7200 0 0);
  --chart-1: oklch(0.8100 0.1700 75.3500);
  --chart-2: oklch(0.5800 0.2100 260.8400);
  --chart-3: oklch(0.5600 0 0);
  --chart-4: oklch(0.4400 0 0);
  --chart-5: oklch(0.9200 0 0);
  --sidebar: oklch(0.1800 0 0);
  --sidebar-foreground: oklch(1 0 0);
  --sidebar-primary: oklch(1 0 0);
  --sidebar-primary-foreground: oklch(0 0 0);
  --sidebar-accent: oklch(0.3200 0 0);
  --sidebar-accent-foreground: oklch(1 0 0);
  --sidebar-border: oklch(0.3200 0 0);
  --sidebar-ring: oklch(0.7200 0 0);
  --font-sans: Inter, ui-sans-serif, sans-serif, system-ui;
  --font-serif: Lora, ui-serif, serif;
  --font-mono: Geist Mono, monospace;
  --radius: 0.5rem;
  --shadow-x: 0px;
  --shadow-y: 1px;
  --shadow-blur: 0px;
  --shadow-spread: -50px;
  --shadow-opacity: 0;
  --shadow-color: hsl(0 0% 0%);
  --shadow-2xs: 0px 1px 0px -50px hsl(0 0% 0% / 0.00);
  --shadow-xs: 0px 1px 0px -50px hsl(0 0% 0% / 0.00);
  --shadow-sm: 0px 1px 0px -50px hsl(0 0% 0% / 0.00), 0px 1px 2px -51px hsl(0 0% 0% / 0.00);
  --shadow: 0px 1px 0px -50px hsl(0 0% 0% / 0.00), 0px 1px 2px -51px hsl(0 0% 0% / 0.00);
  --shadow-md: 0px 1px 0px -50px hsl(0 0% 0% / 0.00), 0px 2px 4px -51px hsl(0 0% 0% / 0.00);
  --shadow-lg: 0px 1px 0px -50px hsl(0 0% 0% / 0.00), 0px 4px 6px -51px hsl(0 0% 0% / 0.00);
  --shadow-xl: 0px 1px 0px -50px hsl(0 0% 0% / 0.00), 0px 8px 10px -51px hsl(0 0% 0% / 0.00);
  --shadow-2xl: 0px 1px 0px -50px hsl(0 0% 0% / 0.00);
}

@theme inline {
  --color-background: var(--background);
  --color-foreground: var(--foreground);
  --color-card: var(--card);
  --color-card-foreground: var(--card-foreground);
  --color-popover: var(--popover);
  --color-popover-foreground: var(--popover-foreground);
  --color-primary: var(--primary);
  --color-primary-foreground: var(--primary-foreground);
  --color-secondary: var(--secondary);
  --color-secondary-foreground: var(--secondary-foreground);
  --color-muted: var(--muted);
  --color-muted-foreground: var(--muted-foreground);
  --color-accent: var(--accent);
  --color-accent-foreground: var(--accent-foreground);
  --color-destructive: var(--destructive);
  --color-destructive-foreground: var(--destructive-foreground);
  --color-border: var(--border);
  --color-input: var(--input);
  --color-ring: var(--ring);
  --color-chart-1: var(--chart-1);
  --color-chart-2: var(--chart-2);
  --color-chart-3: var(--chart-3);
  --color-chart-4: var(--chart-4);
  --color-chart-5: var(--chart-5);
  --color-sidebar: var(--sidebar);
  --color-sidebar-foreground: var(--sidebar-foreground);
  --color-sidebar-primary: var(--sidebar-primary);
  --color-sidebar-primary-foreground: var(--sidebar-primary-foreground);
  --color-sidebar-accent: var(--sidebar-accent);
  --color-sidebar-accent-foreground: var(--sidebar-accent-foreground);
  --color-sidebar-border: var(--sidebar-border);
  --color-sidebar-ring: var(--sidebar-ring);

  --font-sans: var(--font-sans);
  --font-mono: var(--font-mono);
  --font-serif: var(--font-serif);

  --radius-sm: calc(var(--radius) - 4px);
  --radius-md: calc(var(--radius) - 2px);
  --radius-lg: var(--radius);
  --radius-xl: calc(var(--radius) + 4px);

  --shadow-2xs: var(--shadow-2xs);
  --shadow-xs: var(--shadow-xs);
  --shadow-sm: var(--shadow-sm);
  --shadow: var(--shadow);
  --shadow-md: var(--shadow-md);
  --shadow-lg: var(--shadow-lg);
  --shadow-xl: var(--shadow-xl);
  --shadow-2xl: var(--shadow-2xl);
}

@layer base {
  * {
    @apply border-border outline-ring/50;
  }
  body {
    @apply bg-background text-foreground;
  }
}

*** ALWAYS USE THEME VARIABLES ***