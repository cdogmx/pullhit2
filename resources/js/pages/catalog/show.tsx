import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, BarChart3, Loader2, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EbayListings } from '@/components/catalog/ebay-listings';
import { EbayShopButton } from '@/components/catalog/ebay-shop-button';
import { PriceBreakdownDrawer } from '@/components/catalog/price-breakdown-drawer';
import { PriceTag } from '@/components/catalog/price-tag';
import { Sparkline } from '@/components/catalog/sparkline';
import { AddToCollectionDialog } from '@/components/collection/add-to-collection-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { confidenceVariant, formatMoney, relativeTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    CardListings,
    CatalogItem,
    GradingCompanyOption,
    OwnedState,
    PricePoint,
} from '@/types';

type Props = {
    item: { data: CatalogItem };
    gradingCompanies: GradingCompanyOption[];
    /** A background eBay refresh is in flight; poll for the new values. */
    refreshing: boolean;
    /** When the eBay sold data was last pulled (null = never). */
    refreshedAt: string | null;
    /** Recent price-trend points for the sparkline. */
    priceHistory: PricePoint[];
    /** The viewer's owned copies of this card, or null. */
    ownership: OwnedState[] | null;
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
    gradingCompanies,
    refreshing,
    refreshedAt: initialRefreshedAt,
    priceHistory,
    ownership,
}: Props) {
    const user = usePage().props.auth?.user;
    const isAdmin = Boolean(user?.is_admin);
    const attributes = item.attributes ?? {};
    const printings = item.variants ?? [];

    const ownedQty = ownership?.reduce((s, o) => s + o.quantity, 0) ?? 0;
    const ownedGain =
        ownership?.reduce((s, o) => s + (o.unrealized_gain ?? 0), 0) ?? 0;

    // Values + last-updated are stateful so a completed eBay refresh can be
    // swapped in live (no page reload).
    const [values, setValues] = useState(item.market_values ?? []);
    const [refreshedAt, setRefreshedAt] = useState(initialRefreshedAt);
    const [updating, setUpdating] = useState(refreshing);
    const headline =
        values.find((v) => v.state_key === 'NM' || v.state_key === 'SEALED') ??
        values[0];

    const [breakdown, setBreakdown] = useState<{
        stateKey: string;
        label: string;
    } | null>(null);

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
                    setUpdating(false);

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

    const forceRefresh = () => {
        setUpdating(true);
        void fetch(`/admin/cards/${item.id}/refresh`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            credentials: 'same-origin',
        });
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

    const eyebrow = [
        item.set?.name,
        item.number ? `#${item.number}` : null,
        item.language?.toUpperCase(),
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
            <Head title={item.name} />

            <div className="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                <Link
                    href={backHref}
                    className="mb-6 inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Back to browse
                </Link>

                <div className="grid gap-8 md:grid-cols-[minmax(0,320px)_1fr]">
                    {/* Image — sticky alongside the scrolling detail column */}
                    <div className="md:sticky md:top-6 md:self-start">
                        <div className="mx-auto w-full max-w-[300px] overflow-hidden rounded-xl border border-border bg-muted shadow-sm">
                            {item.image_url ? (
                                <img
                                    src={item.image_url}
                                    alt={item.name}
                                    className="aspect-[3/4] w-full object-contain"
                                />
                            ) : (
                                <div className="flex aspect-[3/4] items-center justify-center text-sm text-muted-foreground">
                                    No image
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Detail column — one consistent vertical rhythm */}
                    <div className="min-w-0 space-y-6">
                        {/* Header */}
                        <div>
                            <p className={SECTION_LABEL}>{eyebrow}</p>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight sm:text-3xl">
                                {item.name}
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
                        </div>

                        {/* Owner actions */}
                        {user && (
                            <div className="flex flex-wrap items-center gap-2">
                                <AddToCollectionDialog
                                    catalogItemId={item.id}
                                    gradingCompanies={gradingCompanies}
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

                                <p className="mt-2 text-xs text-muted-foreground">
                                    {refreshedAt
                                        ? `Sold data updated ${relativeTime(refreshedAt)}`
                                        : 'Estimated — no live sold data yet'}
                                </p>

                                {priceHistory.length > 1 && (
                                    <div className="mt-4 rounded-lg border border-border/60 bg-muted/30 p-3">
                                        <Sparkline points={priceHistory} />
                                        <p className="mt-1 text-[11px] text-muted-foreground">
                                            90-day trend
                                        </p>
                                    </div>
                                )}

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
                                        View breakdown
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
                                <span className={SECTION_LABEL}>Market value</span>
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
                                        href={`/catalog/${printing.id}${carryReturn}`}
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
            </div>

            <PriceBreakdownDrawer
                itemId={item.id}
                stateKey={breakdown?.stateKey ?? null}
                label={breakdown?.label ?? null}
                open={breakdown !== null}
                onOpenChange={(o) => !o && setBreakdown(null)}
            />
        </>
    );
}
