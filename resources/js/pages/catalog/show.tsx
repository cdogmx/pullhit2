import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, BarChart3, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PriceBreakdownDrawer } from '@/components/catalog/price-breakdown-drawer';
import { PriceTag } from '@/components/catalog/price-tag';
import { AddToCollectionDialog } from '@/components/collection/add-to-collection-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { confidenceVariant, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CatalogItem, GradingCompanyOption } from '@/types';

type Props = {
    item: { data: CatalogItem };
    gradingCompanies: GradingCompanyOption[];
    /** A background eBay refresh is in flight; poll for the new values. */
    refreshing: boolean;
};

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

export default function Show({
    item: { data: item },
    gradingCompanies,
    refreshing,
}: Props) {
    const user = usePage().props.auth?.user;
    const attributes = item.attributes ?? {};
    const printings = item.variants ?? [];

    // Values are stateful so a completed background eBay refresh can be swapped
    // in live (no page reload).
    const [values, setValues] = useState(item.market_values ?? []);
    const [updating, setUpdating] = useState(refreshing);
    const headline =
        values.find((v) => v.state_key === 'NM' || v.state_key === 'SEALED') ??
        values[0];

    // Poll for the refreshed values while a background update is in flight.
    useEffect(() => {
        if (!updating) {
            return;
        }

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

                if (!body.refreshing) {
                    setValues(body.market_values ?? []);
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
    }, [updating, item.id]);

    const [breakdown, setBreakdown] = useState<{
        stateKey: string;
        label: string;
    } | null>(null);

    // The browse query we came from (search/filters/page), threaded via ?return=.
    const returnSearch =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('return')
            : null;
    const backHref = `/browse${returnSearch ?? ''}`;
    const carryReturn = returnSearch
        ? `?return=${encodeURIComponent(returnSearch)}`
        : '';

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

                <div className="grid gap-8 md:grid-cols-[minmax(0,360px)_1fr]">
                    {/* Image */}
                    <div className="mx-auto w-full max-w-[320px] md:mx-0">
                        <div className="overflow-hidden rounded-xl border border-border bg-muted">
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

                    {/* Info */}
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">
                            {item.set?.name}
                            {item.number ? ` · ${item.number}` : ''}
                            {item.language
                                ? ` · ${item.language.toUpperCase()}`
                                : ''}
                        </p>
                        <h1 className="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">
                            {item.name}
                        </h1>

                        <div className="mt-3 flex flex-wrap gap-1.5">
                            <Badge variant="outline">
                                {humanize(item.item_type)}
                            </Badge>
                            {item.rarity && (
                                <Badge variant="secondary">{item.rarity}</Badge>
                            )}
                            {item.variant && (
                                <Badge>{humanize(item.variant)}</Badge>
                            )}
                        </div>

                        {user && (
                            <div className="mt-4">
                                <AddToCollectionDialog
                                    catalogItemId={item.id}
                                    gradingCompanies={gradingCompanies}
                                />
                            </div>
                        )}

                        {/* Market value (read from market_values; never computed live). */}
                        {headline ? (
                            <div className="mt-6 rounded-lg border border-border p-4">
                                <p className="mb-2 flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                    <span>Market value · {headline.label}</span>
                                    {updating && (
                                        <span className="inline-flex items-center gap-1 text-primary">
                                            <Loader2 className="size-3 animate-spin" />
                                            Updating values…
                                        </span>
                                    )}
                                </p>
                                <PriceTag value={headline} variant="full" />

                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-3"
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

                                {values.length > 1 && (
                                    <div className="mt-4 space-y-0.5 border-t border-border pt-3">
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
                                                className="flex w-full items-center justify-between rounded-md px-1 py-1.5 text-sm transition-colors hover:bg-accent/50"
                                            >
                                                <span className="text-muted-foreground">
                                                    {mv.label}
                                                </span>
                                                <span className="flex items-center gap-2">
                                                    <span className="font-medium">
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
                                )}
                            </div>
                        ) : (
                            <div className="mt-6 rounded-lg border border-dashed border-border p-4">
                                <p className="text-xs font-medium text-muted-foreground">
                                    Market value
                                </p>
                                <p className="mt-1 flex items-center gap-2 text-sm text-muted-foreground">
                                    {updating ? (
                                        <>
                                            <Loader2 className="size-3 animate-spin" />
                                            Fetching values…
                                        </>
                                    ) : (
                                        'No market data yet.'
                                    )}
                                </p>
                            </div>
                        )}

                        {/* Facet details */}
                        {facetRows.length > 0 && (
                            <dl className="mt-6 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                                {facetRows.map((row) => (
                                    <div key={row.label}>
                                        <dt className="text-xs text-muted-foreground">
                                            {row.label}
                                        </dt>
                                        <dd className="font-medium">
                                            {row.value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </div>
                </div>

                {/* Printings of this card */}
                {printings.length > 1 && (
                    <section className="mt-12">
                        <h2 className="mb-3 text-lg font-semibold">
                            Printings ({printings.length})
                        </h2>
                        <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                            {printings.map((printing) => {
                                const isCurrent = printing.id === item.id;
                                const variant = printing.attributes?.variant
                                    ? humanize(
                                          String(printing.attributes.variant),
                                      )
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
                                                    printing.market_value
                                                        .median,
                                                    printing.market_value
                                                        .currency,
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
