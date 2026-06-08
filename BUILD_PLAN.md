# Collectibles Platform — Build Plan (Cursor Brief)

> Working codename: **`tcg-platform`** (rename later). This document is the authoritative engineering brief. Build in the phase order below. Do **not** jump ahead — each phase has guardrails marked **🚫 Not yet**.

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
| **0 — Foundations** | Install starter kit; `AppShell` shared header/footer (marketing + dashboard); design tokens; Sanctum (session + token); S3 disk; queue/scheduler on Laravel Cloud; CI + Pest. | A marketing page and a dummy auth dashboard share one header/footer; mobile bottom nav renders; `/api/v1/ping` works with a token. |
| **1 — Core schema** | Migrations + models for verticals, product_lines, sets, catalog_items (JSON+generated cols), grading_companies, conditions; the **Vertical registry** + Pokémon attribute schema. | Can seed Pokémon vertical + a set + items by hand; faceted attributes validate against the registry. |
| **2 — Mining** | Python adapters (TCGCSV + pokemontcg.io), image pipeline → S3, manifests; `catalog:import` + `observations:import`. | Pokémon catalog (singles + sealed) + images populated from a real run; re-running is idempotent. |
| **3 — Valuation** | `sale_observations` ingestion; engine (MAD outliers, venue priors, velocity EWMA, confidence); `market_values` + recompute jobs; price display component with confidence. | A Pokémon single shows median/IQR/n/confidence/trend; thin-market items show low confidence, not inflated values. |
| **4 — Collections + Scanning** | Collection CRUD, acquisition lots, portfolio analytics; `POST /api/v1/scan` + Pokémon `IdentifierStrategy` with language detection; scan-confirm UI. | User scans a Pokémon card, confirms language/variant, it lands in collection with correct `catalog_item`; collection value uses `market_values`. |
| **5 — Marketplace** | Listings from collection, search/browse, cart, orders, payments (gateway interface), seller payouts. | A user lists an item, another buys it, order + payout recorded. |
| **6 — Mobile/PWA polish + API hardening** | PWA install, offline scan queue, rate limits, API versioning discipline, docs. | Installable PWA; documented stable `/api/v1` ready for a native client. |
| **7 — Second vertical (Sports)** | New seed + attribute schema + source adapter + identifier strategy **only**. | Sports cards work end-to-end with **zero changes** to core tables/valuation/marketplace/collection code. (This phase is the test of the architecture.) |

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

### First task for Cursor

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