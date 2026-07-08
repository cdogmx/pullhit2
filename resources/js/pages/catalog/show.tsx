import { Head, Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    ExternalLink,
    ImagePlus,
    Loader2,
    Maximize2,
    Pencil,
    RefreshCw,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { AddSealedDialog } from '@/components/admin/add-sealed-dialog';
import { EditCardImageDialog } from '@/components/admin/edit-card-image-dialog';
import { EbayListings } from '@/components/catalog/ebay-listings';
import { EbayShopButton } from '@/components/catalog/ebay-shop-button';
import { PriceBreakdownDrawer } from '@/components/catalog/price-breakdown-drawer';
import { PriceHistoryChart } from '@/components/catalog/price-history-chart';
import { PriceTag } from '@/components/catalog/price-tag';
import { ShareButtons } from '@/components/catalog/share-buttons';
import { SuggestEditDialog } from '@/components/catalog/suggest-edit-dialog';
import { AddToCollectionDialog } from '@/components/collection/add-to-collection-dialog';
import { HScroller } from '@/components/shared/h-scroller';
import { Badge } from '@/components/ui/badge';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { WishlistButton } from '@/components/wishlist/wishlist-button';
import {
    cardHref,
    confidenceVariant,
    formatMoney,
    languageLabel,
    relativeTime,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    CardListings,
    CatalogItem,
    GradingCompanyOption,
    OwnedState,
    PriceHistory,
    PricePoint,
} from '@/types';

type Props = {
    item: { data: CatalogItem };
    /** Server-built SEO/share meta — its title drives the page <title>. */
    meta: { title: string };
    gradingCompanies: GradingCompanyOption[];
    /** A background eBay refresh is in flight; poll for the new values. */
    refreshing: boolean;
    /** When the eBay sold data was last pulled (null = never). */
    refreshedAt: string | null;
    /** Weekly-median sold-price history (the card's raw state) + estimated flag. */
    priceHistory: PriceHistory;
    /** Long-term monthly series (PriceCharting), keyed by grade tier, for "Max". */
    priceHistoryLong: Record<string, PricePoint[]>;
    /** The most recent real sold comp (raw state), or null. */
    lastSale: { price: number; sold_at: string; venue: string } | null;
    /** The viewer's owned copies of this card, or null. */
    ownership: OwnedState[] | null;
    /** Whether the viewer has this card on their wishlist. */
    wishlisted: boolean;
    /** Sealed-product editor options (admin). */
    sealedTypes: string[];
    languages: string[];
    /** Other base cards in the same set, for the horizontal scroller. */
    moreInSet: CatalogItem[];
    /** The same card in other languages (e.g. the Japanese printing). */
    otherLanguages: {
        language: string;
        name: string;
        set: string | null;
        url: string;
    }[];
    /** Live "where to buy" offers from the deals tracker (retailer, price, stock). */
    whereToBuy: WhereToBuyOffer[];
};

type WhereToBuyOffer = {
    retailer: string;
    url: string;
    /** Last observed price in cents, or null if never checked. */
    price_cents: number | null;
    in_stock: boolean;
    checked_at: string | null;
};

/** Read Laravel's XSRF-TOKEN cookie for same-origin POSTs. */
function xsrfToken(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

const humanize = (value?: string | null): string =>
    (value ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/** Facet keys surfaced as labelled detail rows, in display order. */
const DETAIL_FACETS: { key: string; label: string }[] = [
    { key: 'rarity', label: 'Rarity' },
    { key: 'variant', label: 'Printing' },
    { key: 'illustrator', label: 'Illustrator' },
    { key: 'hp', label: 'HP' },
    { key: 'type', label: 'Type' },
    { key: 'sealed_type', label: 'Sealed type' },
    { key: 'pack_count', label: 'Packs' },
];

/** Shared section styling for a consistent, organized page rhythm. */
const PANEL = 'rounded-xl border border-border bg-card p-5';
const SECTION_LABEL =
    'text-xs font-semibold uppercase tracking-wide text-muted-foreground';

export default function Show({
    item: { data: item },
    meta,
    gradingCompanies,
    wishlisted,
    refreshing,
    refreshedAt: initialRefreshedAt,
    priceHistory,
    priceHistoryLong: initialPriceHistoryLong,
    lastSale: initialLastSale,
    ownership,
    sealedTypes,
    languages,
    moreInSet,
    otherLanguages,
    whereToBuy,
}: Props) {
    const user = usePage().props.auth?.user;
    const isAdmin = Boolean(user?.is_admin);
    const attributes = item.attributes ?? {};
    const printings = item.variants ?? [];

    const ownedQty = ownership?.reduce((s, o) => s + o.quantity, 0) ?? 0;
    const ownedGain =
        ownership?.reduce((s, o) => s + (o.unrealized_gain ?? 0), 0) ?? 0;

    // Values, last-sale, and last-updated are stateful so a completed eBay refresh
    // can be swapped in live (no page reload); a chartVersion bump re-fetches the
    // price-history chart's series too.
    const [values, setValues] = useState(item.market_values ?? []);
    const [refreshedAt, setRefreshedAt] = useState(initialRefreshedAt);
    const [lastSale, setLastSale] = useState(initialLastSale);
    const [longTerm, setLongTerm] = useState(initialPriceHistoryLong);
    const [updating, setUpdating] = useState(refreshing);
    const [chartVersion, setChartVersion] = useState(0);
    const headline =
        values.find((v) => v.state_key === 'NM' || v.state_key === 'SEALED') ??
        values[0];

    // Sealed appreciation: how the current sealed value compares to the original
    // release MSRP (both in cents). Only meaningful for sealed product with an
    // MSRP on file and a real headline value.
    const sealedVsMsrp = (() => {
        if (
            item.item_type !== 'sealed' ||
            !item.msrp ||
            !headline ||
            headline.median == null
        ) {
            return null;
        }

        return {
            msrp: item.msrp,
            pct: ((headline.median - item.msrp) / item.msrp) * 100,
            // Only a real citation URL becomes a link; 'admin'/'ai_search' don't.
            source: item.msrp_source?.startsWith('http')
                ? item.msrp_source
                : null,
        };
    })();

    const [breakdown, setBreakdown] = useState<{
        stateKey: string;
        label: string;
    } | null>(null);
    const [editingSealed, setEditingSealed] = useState(false);
    const [zoomed, setZoomed] = useState(false);
    const [editingImage, setEditingImage] = useState(false);
    const isSealed = item.item_type === 'sealed';

    // Buy links + live listings (lazy — drives the prominent "Shop on eBay" CTA).
    const [listings, setListings] = useState<CardListings | null>(null);
    useEffect(() => {
        let active = true;
        fetch(`/api/v1/catalog/${item.id}/listings`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((d: CardListings) => active && setListings(d))
            .catch(() => {});

        return () => {
            active = false;
        };
    }, [item.id]);

    // Poll while an update is in flight; stop once refreshed_at advances past
    // where we started (covers both on-view auto-refresh and admin "Refresh now").
    useEffect(() => {
        if (!updating) {
            return;
        }

        const baseline = refreshedAt;
        let active = true;
        let attempts = 0;
        let timer: ReturnType<typeof setTimeout>;

        const tick = async () => {
            attempts += 1;

            try {
                const res = await fetch(`/api/v1/catalog/${item.id}/values`, {
                    headers: { Accept: 'application/json' },
                });
                const body = await res.json();

                if (!active) {
                    return;
                }

                if (body.refreshed_at && body.refreshed_at !== baseline) {
                    setValues(body.market_values ?? []);
                    setRefreshedAt(body.refreshed_at);
                    setLastSale(body.last_sale ?? null);

                    if (body.price_history_long) {
                        setLongTerm(body.price_history_long);
                    }

                    setUpdating(false);
                    // Re-fetch the price-history chart's series with the new data.
                    setChartVersion((v) => v + 1);
                    toast.success('Prices updated');

                    return;
                }
            } catch {
                // keep trying
            }

            if (active && attempts < 15) {
                timer = setTimeout(tick, 4000);
            } else if (active) {
                setUpdating(false);
            }
        };

        timer = setTimeout(tick, 4000);

        return () => {
            active = false;
            clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [updating, item.id]);

    const forceRefresh = async () => {
        setUpdating(true);

        try {
            // Synchronous pull — values are ready when this resolves; the poll
            // loop then swaps them in once refreshed_at advances.
            const res = await fetch(`/admin/cards/${item.id}/refresh`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                credentials: 'same-origin',
            });
            const body = await res.json().catch(() => ({}));

            if (!res.ok || body.ok === false) {
                setUpdating(false); // disabled or daily cap reached
            } else if (body.price_history_long) {
                // Refresh also re-pulled PriceCharting — show the fresh long-term
                // line immediately (the value poll swaps in the rest).
                setLongTerm(body.price_history_long);
                setChartVersion((v) => v + 1);
            }
        } catch {
            setUpdating(false);
        }
    };

    // The browse query we came from (search/filters/page), threaded via ?return=.
    const returnSearch =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('return')
            : null;
    const backHref = `/browse${returnSearch ?? ''}`;
    const carryReturn = returnSearch
        ? `?return=${encodeURIComponent(returnSearch)}`
        : '';

    // Breadcrumb: Browse → brand → set → card → variant (whatever's available).
    const line = item.product_line;
    const set = item.set;
    const printingLabel = [
        attributes.edition ? humanize(String(attributes.edition)) : null,
        attributes.variant && attributes.variant !== 'normal'
            ? humanize(String(attributes.variant))
            : null,
        attributes.finish ? humanize(String(attributes.finish)) : null,
    ]
        .filter(Boolean)
        .join(' · ');
    const crumbs: { label: string; href?: string; back?: boolean }[] = [
        // When we arrived from a browse search, "Browse" goes back through history
        // so Inertia restores that page's loaded list + scroll position.
        { label: 'Browse', href: backHref, back: !!returnSearch },
        ...(line ? [{ label: line.name, href: `/browse/${line.slug}` }] : []),
        ...(line && set
            ? [{ label: set.name, href: `/browse/${line.slug}/${set.slug}` }]
            : []),
        {
            label: item.name,
            ...(printingLabel && line && set
                ? {
                      href: `/browse/${line.slug}/${set.slug}?q=${encodeURIComponent(item.name)}`,
                  }
                : {}),
        },
        ...(printingLabel ? [{ label: printingLabel }] : []),
    ];

    const eyebrow = [
        item.set?.name,
        item.number ? `#${item.number}` : null,
        languageLabel(item.language),
    ]
        .filter(Boolean)
        .join('  ·  ');

    const facetRows = DETAIL_FACETS.filter(
        (f) => attributes[f.key] !== undefined && attributes[f.key] !== null,
    ).map((f) => ({
        label: f.label,
        value:
            f.key === 'variant'
                ? humanize(String(attributes[f.key]))
                : String(attributes[f.key]),
    }));

    return (
        <>
            {/* Use the server meta title verbatim (Card #Num - Set - Brand) so
                the tab matches the server-rendered <title>; a child <title>
                bypasses the global "… - CardFoo" suffix. */}
            <Head>
                <title>{meta.title}</title>
            </Head>

            <div className="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                <Breadcrumb className="mb-6">
                    <BreadcrumbList>
                        {crumbs.map((c, i) => (
                            <Fragment key={`${c.label}-${i}`}>
                                {i > 0 && <BreadcrumbSeparator />}
                                <BreadcrumbItem>
                                    {c.href && i < crumbs.length - 1 ? (
                                        <BreadcrumbLink asChild>
                                            <Link
                                                href={c.href}
                                                onClick={
                                                    c.back
                                                        ? (e) => {
                                                              if (
                                                                  window.history
                                                                      .length > 1
                                                              ) {
                                                                  e.preventDefault();
                                                                  window.history.back();
                                                              }
                                                          }
                                                        : undefined
                                                }
                                            >
                                                {c.label}
                                            </Link>
                                        </BreadcrumbLink>
                                    ) : (
                                        <BreadcrumbPage>
                                            {c.label}
                                        </BreadcrumbPage>
                                    )}
                                </BreadcrumbItem>
                            </Fragment>
                        ))}
                    </BreadcrumbList>
                </Breadcrumb>

                <div className="grid gap-8 md:grid-cols-[minmax(0,320px)_1fr]">
                    {/* Image — sticky alongside the scrolling detail column;
                        top clears the sticky h-16 site header (64px) plus a gap. */}
                    <div className="md:sticky md:top-20 md:self-start">
                        <div className="mx-auto w-full max-w-[300px] overflow-hidden rounded-xl border border-border bg-muted shadow-sm">
                            {item.image_url ? (
                                <button
                                    type="button"
                                    onClick={() => setZoomed(true)}
                                    className="group/img relative block w-full cursor-zoom-in"
                                    aria-label="Enlarge image"
                                >
                                    <img
                                        src={item.image_url}
                                        alt={item.name}
                                        className="aspect-[3/4] w-full object-contain"
                                    />
                                    <span className="pointer-events-none absolute right-2 bottom-2 flex size-8 items-center justify-center rounded-full bg-background/80 text-foreground opacity-0 shadow backdrop-blur transition-opacity group-hover/img:opacity-100">
                                        <Maximize2 className="size-4" />
                                    </span>
                                </button>
                            ) : (
                                <div className="flex aspect-[3/4] items-center justify-center text-sm text-muted-foreground">
                                    No image
                                </div>
                            )}
                        </div>
                        {isAdmin && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="mt-2 w-full max-w-[300px]"
                                onClick={() => setEditingImage(true)}
                            >
                                <ImagePlus className="size-4" />
                                Edit image
                            </Button>
                        )}
                    </div>

                    {/* Detail column — one consistent vertical rhythm */}
                    <div className="min-w-0 space-y-6">
                        {/* Header */}
                        <div>
                            <p className={SECTION_LABEL}>{eyebrow}</p>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight sm:text-3xl">
                                {item.display_name ?? item.name}
                            </h1>
                            <div className="mt-3 flex flex-wrap gap-1.5">
                                <Badge variant="outline">
                                    {humanize(item.item_type)}
                                </Badge>
                                {item.rarity && (
                                    <Badge variant="secondary">
                                        {item.rarity}
                                    </Badge>
                                )}
                                {item.variant && (
                                    <Badge>{humanize(item.variant)}</Badge>
                                )}
                            </div>

                            <div className="mt-4">
                                <ShareButtons
                                    title={item.display_name ?? item.name}
                                    path={item.url}
                                    text={`${item.display_name ?? item.name}${
                                        item.set
                                            ? ` (${item.set.name}${item.number ? ` #${item.number}` : ''})`
                                            : ''
                                    } — price & value on CardFoo`}
                                />
                            </div>
                        </div>

                        {/* Owner actions */}
                        {user && (
                            <div className="flex flex-wrap items-center gap-2">
                                <AddToCollectionDialog
                                    catalogItemId={item.id}
                                    gradingCompanies={gradingCompanies}
                                />
                                <WishlistButton
                                    catalogItemId={item.id}
                                    wishlisted={wishlisted}
                                />
                                {ownership && (
                                    <Link
                                        href="/collection"
                                        className="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/50 px-3 py-1 text-xs font-medium transition-colors hover:bg-muted"
                                        title={ownership
                                            .map(
                                                (o) =>
                                                    `${o.state_label} ×${o.quantity}`,
                                            )
                                            .join(', ')}
                                    >
                                        In your collection · ×{ownedQty}
                                        {ownedGain !== 0 && (
                                            <span
                                                className={cn(
                                                    'font-semibold',
                                                    ownedGain > 0
                                                        ? 'text-emerald-600 dark:text-emerald-400'
                                                        : 'text-red-600 dark:text-red-400',
                                                )}
                                            >
                                                {ownedGain > 0 ? '+' : ''}
                                                {formatMoney(ownedGain)}
                                            </span>
                                        )}
                                    </Link>
                                )}
                            </div>
                        )}

                        {user && (
                            <SuggestEditDialog
                                item={item}
                                trigger={
                                    <button
                                        type="button"
                                        className="self-start text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                    >
                                        Suggest an edit
                                    </button>
                                }
                            />
                        )}

                        {/* Market value (read from market_values; never live). */}
                        {headline ? (
                            <div className={PANEL}>
                                <div className="flex items-center justify-between gap-2">
                                    <span className={SECTION_LABEL}>
                                        Market value · {headline.label}
                                    </span>
                                    {updating && (
                                        <Badge className="gap-1 border-transparent bg-amber-500 text-white hover:bg-amber-500">
                                            <Loader2 className="size-3 animate-spin" />
                                            Updating
                                        </Badge>
                                    )}
                                </div>

                                <div className="mt-3">
                                    <PriceTag value={headline} variant="full" />
                                </div>

                                {sealedVsMsrp && (
                                    <p className="mt-2 flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                                        <span
                                            className={cn(
                                                'inline-flex items-center gap-0.5 font-semibold',
                                                sealedVsMsrp.pct >= 0
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-red-600 dark:text-red-400',
                                            )}
                                        >
                                            {sealedVsMsrp.pct >= 0 ? (
                                                <TrendingUp className="size-3.5" />
                                            ) : (
                                                <TrendingDown className="size-3.5" />
                                            )}
                                            {sealedVsMsrp.pct >= 0 ? '+' : ''}
                                            {sealedVsMsrp.pct.toFixed(0)}%
                                        </span>
                                        since release ·{' '}
                                        {formatMoney(sealedVsMsrp.msrp)} MSRP
                                        {sealedVsMsrp.source && (
                                            <a
                                                href={sealedVsMsrp.source}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="underline underline-offset-2 hover:text-foreground"
                                            >
                                                source
                                            </a>
                                        )}
                                    </p>
                                )}

                                {lastSale ? (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Last sold{' '}
                                        <span className="font-semibold text-foreground">
                                            {formatMoney(lastSale.price)}
                                        </span>{' '}
                                        · {relativeTime(lastSale.sold_at)} on{' '}
                                        {lastSale.venue}
                                    </p>
                                ) : (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {headline.is_estimated
                                            ? 'Estimated — no live sold data yet'
                                            : refreshedAt
                                              ? `Sold data updated ${relativeTime(refreshedAt)}`
                                              : 'No recent sold data'}
                                    </p>
                                )}

                                <PriceHistoryChart
                                    history={priceHistory}
                                    itemId={item.id}
                                    states={values.map((v) => ({
                                        state_key: v.state_key,
                                        label: v.label,
                                        grade: v.grade,
                                    }))}
                                    defaultStateKey={headline.state_key}
                                    longTermByTier={longTerm}
                                    refreshKey={chartVersion}
                                />

                                {/* Primary actions — prominent, side by side */}
                                <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                                    {listings && (
                                        <EbayShopButton
                                            options={listings.ebay_options}
                                            className="sm:flex-1"
                                        />
                                    )}
                                    <Button
                                        variant="secondary"
                                        className="sm:flex-1"
                                        onClick={() =>
                                            setBreakdown({
                                                stateKey: headline.state_key,
                                                label: headline.label,
                                            })
                                        }
                                    >
                                        <BarChart3 className="size-4" />
                                        {headline.is_estimated || !headline.n_sales
                                            ? 'View breakdown'
                                            : `See ${headline.n_sales} sold comps`}
                                    </Button>
                                </div>

                                {listings && listings.listings.length > 0 && (
                                    <div className="mt-4 border-t border-border pt-3">
                                        <p className={cn(SECTION_LABEL, 'mb-2')}>
                                            Available on eBay
                                        </p>
                                        <EbayListings listings={listings.listings} />
                                    </div>
                                )}

                                <div className="mt-3 flex items-center justify-between gap-2">
                                    <p className="text-[11px] text-muted-foreground">
                                        As an eBay Partner, we may be compensated
                                        for qualifying purchases.
                                    </p>
                                    {isAdmin && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={forceRefresh}
                                            disabled={updating}
                                        >
                                            <RefreshCw
                                                className={cn(
                                                    'size-4',
                                                    updating && 'animate-spin',
                                                )}
                                            />
                                            Refresh
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <div className={cn(PANEL, 'border-dashed')}>
                                <div className="flex items-center justify-between gap-2">
                                    <span className={SECTION_LABEL}>
                                        Market value
                                    </span>
                                    {isAdmin && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={forceRefresh}
                                            disabled={updating}
                                        >
                                            <RefreshCw
                                                className={cn(
                                                    'size-4',
                                                    updating && 'animate-spin',
                                                )}
                                            />
                                            Get values
                                        </Button>
                                    )}
                                </div>
                                <div className="mt-2 text-sm text-muted-foreground">
                                    {updating ? (
                                        <Badge className="gap-1 border-transparent bg-amber-500 text-white hover:bg-amber-500">
                                            <Loader2 className="size-3 animate-spin" />
                                            Fetching values…
                                        </Badge>
                                    ) : (
                                        'No market data yet.'
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Where to buy — live retailer offers from the deals
                            tracker, plus MSRP + release date (sealed products). */}
                        {(whereToBuy.length > 0 ||
                            item.msrp ||
                            item.released_at ||
                            (isAdmin && isSealed)) && (
                            <div className={PANEL}>
                                <div className="flex items-center justify-between gap-2">
                                    <span className={SECTION_LABEL}>
                                        Where to buy
                                    </span>
                                    {isAdmin && isSealed && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setEditingSealed(true)
                                            }
                                        >
                                            <Pencil className="size-4" /> Edit
                                        </Button>
                                    )}
                                </div>
                                {(item.msrp || item.released_at) && (
                                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                        {item.msrp ? (
                                            <span>
                                                MSRP {formatMoney(item.msrp)}
                                            </span>
                                        ) : null}
                                        {item.released_at ? (
                                            <span>
                                                Released {item.released_at}
                                            </span>
                                        ) : null}
                                    </div>
                                )}
                                {whereToBuy.length > 0 ? (
                                    <div className="mt-3 space-y-2">
                                        {whereToBuy.map((offer, i) => (
                                            <a
                                                key={i}
                                                href={offer.url}
                                                target="_blank"
                                                rel="noopener noreferrer sponsored"
                                                className="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-sm transition-colors hover:border-ring hover:bg-accent/40"
                                            >
                                                <span className="flex min-w-0 items-center gap-2">
                                                    <span className="truncate font-medium">
                                                        {offer.retailer}
                                                    </span>
                                                    <Badge
                                                        variant={
                                                            offer.in_stock
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                        className="shrink-0 text-[10px]"
                                                    >
                                                        {offer.in_stock
                                                            ? 'In stock'
                                                            : 'Out of stock'}
                                                    </Badge>
                                                </span>
                                                <span className="flex items-center gap-2 text-muted-foreground">
                                                    {offer.price_cents != null && (
                                                        <span className="font-semibold text-foreground">
                                                            {formatMoney(
                                                                offer.price_cents,
                                                            )}
                                                        </span>
                                                    )}
                                                    <ExternalLink className="size-4" />
                                                </span>
                                            </a>
                                        ))}
                                        <p className="text-[11px] text-muted-foreground">
                                            Live prices tracked across retailers —
                                            see all on the{' '}
                                            <Link
                                                href="/deals"
                                                className="underline underline-offset-2 hover:text-foreground"
                                            >
                                                deals tracker
                                            </Link>
                                            .
                                        </p>
                                    </div>
                                ) : isAdmin ? (
                                    <p className="mt-3 text-sm text-muted-foreground">
                                        No retailer offers tracked yet — add store
                                        links for this card in the{' '}
                                        <Link
                                            href="/admin/stock-alerts"
                                            className="underline underline-offset-2 hover:text-foreground"
                                        >
                                            deals tracker
                                        </Link>
                                        .
                                    </p>
                                ) : null}
                            </div>
                        )}

                        {/* Prices by condition / grade */}
                        {values.length > 1 && (
                            <section>
                                <h2 className={cn(SECTION_LABEL, 'mb-2')}>
                                    Prices by condition
                                </h2>
                                <div className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                                    {values.map((mv) => (
                                        <button
                                            key={mv.state_key}
                                            type="button"
                                            onClick={() =>
                                                setBreakdown({
                                                    stateKey: mv.state_key,
                                                    label: mv.label,
                                                })
                                            }
                                            className="flex w-full items-center justify-between gap-2 px-4 py-2.5 text-sm transition-colors hover:bg-accent/50"
                                        >
                                            <span className="text-muted-foreground">
                                                {mv.label}
                                            </span>
                                            <span className="flex items-center gap-2">
                                                <span className="font-semibold">
                                                    {formatMoney(
                                                        mv.median,
                                                        mv.currency,
                                                    )}
                                                </span>
                                                <Badge
                                                    variant={confidenceVariant(
                                                        mv.confidence_label,
                                                    )}
                                                    className="text-[10px]"
                                                >
                                                    {mv.confidence_label}
                                                </Badge>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </section>
                        )}

                        {/* Same card in other languages */}
                        {otherLanguages.length > 0 && (
                            <section>
                                <h2 className={cn(SECTION_LABEL, 'mb-2')}>
                                    Other languages
                                </h2>
                                <div className="flex flex-wrap gap-2">
                                    {otherLanguages.map((o) => (
                                        <Link
                                            key={o.url}
                                            href={o.url}
                                            className="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-1.5 text-sm transition-colors hover:border-ring hover:bg-accent/40"
                                        >
                                            <Badge
                                                variant="secondary"
                                                className="text-[10px]"
                                            >
                                                {languageLabel(o.language)}
                                            </Badge>
                                            <span className="max-w-[12rem] truncate">
                                                {o.name}
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            </section>
                        )}

                        {/* Card details */}
                        {facetRows.length > 0 && (
                            <section>
                                <h2 className={cn(SECTION_LABEL, 'mb-3')}>
                                    Card details
                                </h2>
                                <dl className="grid grid-cols-2 gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
                                    {facetRows.map((row) => (
                                        <div key={row.label}>
                                            <dt className="text-xs text-muted-foreground">
                                                {row.label}
                                            </dt>
                                            <dd className="mt-0.5 font-medium">
                                                {row.value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </section>
                        )}
                    </div>
                </div>

                {/* Other printings of this card */}
                {printings.length > 1 && (
                    <section className="mt-12">
                        <h2 className="mb-3 text-lg font-semibold">
                            Other printings ({printings.length})
                        </h2>
                        <div className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                            {printings.map((printing) => {
                                const isCurrent = printing.id === item.id;
                                const variant = printing.attributes?.variant
                                    ? humanize(String(printing.attributes.variant))
                                    : (printing.variant ?? '—');

                                return (
                                    <Link
                                        key={printing.id}
                                        href={`${cardHref(printing)}${carryReturn}`}
                                        className={cn(
                                            'flex items-center gap-3 p-3 transition-colors hover:bg-accent/40',
                                            isCurrent && 'bg-accent/60',
                                        )}
                                        preserveScroll
                                    >
                                        <div className="h-14 w-10 shrink-0 overflow-hidden rounded bg-muted">
                                            {printing.image_url && (
                                                <img
                                                    src={printing.image_url}
                                                    alt={printing.name}
                                                    className="size-full object-contain"
                                                />
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium">
                                                {variant}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {printing.number}
                                                {printing.rarity
                                                    ? ` · ${printing.rarity}`
                                                    : ''}
                                            </p>
                                        </div>
                                        {printing.market_value && (
                                            <span className="text-sm font-medium">
                                                {formatMoney(
                                                    printing.market_value.median,
                                                    printing.market_value.currency,
                                                )}
                                            </span>
                                        )}
                                        {isCurrent && (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                Viewing
                                            </Badge>
                                        )}
                                    </Link>
                                );
                            })}
                        </div>
                    </section>
                )}

                {/* More cards in this set */}
                {moreInSet.length > 0 && (
                    <section className="mt-8">
                        <div className="mb-3 flex items-baseline justify-between gap-2">
                            <h2 className={SECTION_LABEL}>
                                {attributes.rarity
                                    ? `More ${attributes.rarity} in ${item.set?.name ?? 'this set'}`
                                    : `More in ${item.set?.name ?? 'this set'}`}
                            </h2>
                            {item.set && item.product_line && (
                                <Link
                                    href={`/browse/${item.product_line.slug}/${item.set.slug}`}
                                    className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                                >
                                    View all
                                </Link>
                            )}
                        </div>
                        <HScroller>
                            {moreInSet.map((card) => (
                                <Link
                                    key={card.id}
                                    href={cardHref(card)}
                                    draggable={false}
                                    className="group w-28 shrink-0 snap-start"
                                >
                                    <div className="aspect-[5/7] overflow-hidden rounded-lg border border-border bg-muted">
                                        {card.image_url ? (
                                            <img
                                                src={card.image_url}
                                                alt={card.display_name ?? card.name}
                                                loading="lazy"
                                                className="size-full object-contain transition-transform group-hover:scale-105"
                                            />
                                        ) : (
                                            <div className="flex size-full items-center justify-center px-1 text-center text-[10px] text-muted-foreground">
                                                {card.display_name ?? card.name}
                                            </div>
                                        )}
                                    </div>
                                    <p className="mt-1.5 truncate text-xs font-medium">
                                        {card.display_name ?? card.name}
                                    </p>
                                    <p className="truncate text-[11px] text-muted-foreground">
                                        {card.number}
                                        {card.market_value
                                            ? ` · ${formatMoney(card.market_value.median, card.market_value.currency)}`
                                            : ''}
                                    </p>
                                </Link>
                            ))}
                        </HScroller>
                    </section>
                )}
            </div>

            {item.image_url && (
                <Dialog open={zoomed} onOpenChange={setZoomed}>
                    <DialogContent className="border-0 bg-transparent p-0 shadow-none sm:max-w-2xl [&>button]:bg-background/80 [&>button]:rounded-full [&>button]:p-1.5">
                        <DialogTitle className="sr-only">
                            {item.display_name ?? item.name}
                        </DialogTitle>
                        <img
                            src={item.image_url}
                            alt={item.display_name ?? item.name}
                            className="mx-auto max-h-[88vh] w-auto rounded-xl object-contain"
                        />
                    </DialogContent>
                </Dialog>
            )}

            {isAdmin && (
                <EditCardImageDialog
                    catalogItemId={item.id}
                    name={item.display_name ?? item.name}
                    currentUrl={item.image_url ?? null}
                    open={editingImage}
                    onOpenChange={setEditingImage}
                />
            )}

            <PriceBreakdownDrawer
                itemId={item.id}
                stateKey={breakdown?.stateKey ?? null}
                label={breakdown?.label ?? null}
                open={breakdown !== null}
                onOpenChange={(o) => !o && setBreakdown(null)}
            />

            {isAdmin && isSealed && (
                <AddSealedDialog
                    item={item}
                    sealedTypes={sealedTypes}
                    languages={languages}
                    open={editingSealed}
                    onOpenChange={setEditingSealed}
                />
            )}
        </>
    );
}
