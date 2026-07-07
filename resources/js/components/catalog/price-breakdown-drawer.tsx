import { ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PriceTag } from '@/components/catalog/price-tag';
import { Badge } from '@/components/ui/badge';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PriceBreakdown } from '@/types';

/**
 * Right-side drawer detailing how a priced state's value was determined: the
 * distribution, methodology, source breakdown, and the actual sold comps
 * (linked, outliers marked). Lazily fetches the comps when opened.
 */
export function PriceBreakdownDrawer({
    itemId,
    stateKey,
    label,
    open,
    onOpenChange,
}: {
    itemId: number;
    stateKey: string | null;
    label: string | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    // Keyed by stateKey so stale results are ignored while switching states.
    const [entry, setEntry] = useState<{
        stateKey: string;
        data: PriceBreakdown;
    } | null>(null);

    useEffect(() => {
        if (!open || !stateKey) {
            return;
        }

        let active = true;
        const key = stateKey;

        fetch(
            `/api/v1/catalog/${itemId}/observations?state_key=${encodeURIComponent(key)}`,
            { headers: { Accept: 'application/json' } },
        )
            .then((r) => r.json())
            .then(
                (json: PriceBreakdown) =>
                    active && setEntry({ stateKey: key, data: json }),
            )
            .catch(
                () =>
                    active &&
                    setEntry({
                        stateKey: key,
                        data: { value: null, observations: [], sources: {} },
                    }),
            );

        return () => {
            active = false;
        };
    }, [open, stateKey, itemId]);

    const ready = entry?.stateKey === stateKey;
    const loading = open && !!stateKey && !ready;
    const value = ready ? (entry?.data.value ?? null) : null;
    const observations = ready ? (entry?.data.observations ?? []) : [];
    const sources = ready ? (entry?.data.sources ?? {}) : {};

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full overflow-y-auto sm:max-w-md"
            >
                <SheetHeader>
                    <SheetTitle>Price breakdown{label ? ` · ${label}` : ''}</SheetTitle>
                    <SheetDescription>
                        How this value was determined, and the sales behind it.
                    </SheetDescription>
                </SheetHeader>

                <div className="space-y-6 px-4 pb-8">
                    {loading && (
                        <div className="space-y-3">
                            <Skeleton className="h-16 w-full" />
                            <Skeleton className="h-24 w-full" />
                        </div>
                    )}

                    {!loading && value && (
                        <>
                            <PriceTag value={value} variant="full" />

            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <Stat label="Middle 50% of sales">
                                    {formatMoney(value.p25, value.currency)}–
                                    {formatMoney(value.p75, value.currency)}
                                </Stat>
                                <Stat label="Full sold range">
                                    {formatMoney(value.low, value.currency)}–
                                    {formatMoney(value.high, value.currency)}
                                </Stat>
                                <Stat label="Half-life">
                                    {value.half_life_days} days
                                </Stat>
                                <Stat label="Comps used">{value.n_sales}</Stat>
                            </dl>

                            <p className="-mt-2 text-xs text-muted-foreground">
                                These are the actual sold prices, not a confidence
                                figure — a tighter range means more agreement, hence
                                higher confidence.
                            </p>

                            {/* Sources */}
                            {Object.keys(sources).length > 0 && (
                                <div>
                                    <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        Sources
                                    </h3>
                                    <div className="flex flex-wrap gap-1.5">
                                        {Object.entries(sources).map(([venue, n]) => (
                                            <Badge key={venue} variant="secondary">
                                                {venue} · {n}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Methodology */}
                            <div className="rounded-lg border border-border bg-muted/40 p-3 text-xs text-muted-foreground">
                                Median of recency-weighted sold comps (EWMA). Outliers
                                are rejected with a robust Hampel/MAD filter and shown
                                below as excluded; venues are normalized for known bias
                                before blending.
                            </div>

                            {/* Comps */}
                            {value.is_estimated ? (
                                <div className="rounded-lg border border-dashed border-border p-3 text-sm text-muted-foreground">
                                    Estimated from {observations.length} modeled data
                                    points — no live sold listings yet. A real value
                                    appears once sold comps are pulled.
                                </div>
                            ) : (
                                <div>
                                    <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        Sold comps ({observations.length})
                                    </h3>
                                    <ul className="divide-y divide-border">
                                        {observations.map((o, i) => (
                                            <li
                                                key={i}
                                                className={cn(
                                                    'flex items-start justify-between gap-3 py-2',
                                                    o.is_outlier && 'opacity-50',
                                                )}
                                            >
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm">
                                                        {o.url ? (
                                                            <a
                                                                href={o.url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="inline-flex items-center gap-1 hover:underline"
                                                            >
                                                                {o.title ?? 'View listing'}
                                                                <ExternalLink className="size-3 shrink-0" />
                                                            </a>
                                                        ) : (
                                                            (o.title ?? 'Listing')
                                                        )}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {o.venue}
                                                        {o.sold_on ? ` · ${o.sold_on}` : ''}
                                                        {o.is_outlier ? ' · excluded' : ''}
                                                    </p>
                                                </div>
                                                <span className="shrink-0 text-sm font-medium">
                                                    {formatMoney(o.price, o.currency)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </>
                    )}

                    {!loading && !value && (
                        <p className="text-sm text-muted-foreground">
                            No market data for this state yet.
                        </p>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function Stat({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium">{children}</dd>
        </div>
    );
}
