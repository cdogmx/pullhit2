import { ExternalLink, Loader2, ShoppingCart } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CardListings, EbayListing, EbayOption } from '@/types';

const REL = 'nofollow sponsored noopener';

/**
 * Live eBay "buy it now" listings for a card, with a condition/grade selector
 * that reloads Browse results for the chosen refinement.
 */
export function EbayListingsPanel({
    catalogItemId,
    initial,
    className,
}: {
    catalogItemId: number;
    initial: CardListings | null;
    className?: string;
}) {
    const [data, setData] = useState<CardListings | null>(initial);
    const [option, setOption] = useState(
        initial?.selected ?? initial?.ebay_options?.[0]?.label ?? 'Near Mint',
    );
    const [loading, setLoading] = useState(false);

    // Sync when the parent finishes its first fetch.
    useEffect(() => {
        if (initial) {
            setData(initial);
            setOption(initial.selected ?? initial.ebay_options[0]?.label ?? 'Near Mint');
        }
    }, [initial]);

    const shopUrl = useMemo(() => {
        const match = data?.ebay_options?.find((o) => o.label === option);

        return match?.url ?? data?.ebay_options?.[0]?.url ?? null;
    }, [data, option]);

    const changeOption = (label: string) => {
        setOption(label);
        setLoading(true);

        fetch(
            `/api/v1/catalog/${catalogItemId}/listings?option=${encodeURIComponent(label)}`,
            { headers: { Accept: 'application/json' } },
        )
            .then((r) => r.json())
            .then((d: CardListings) => {
                setData(d);
                setOption(d.selected ?? label);
            })
            .catch(() => {})
            .finally(() => setLoading(false));
    };

    if (!data && !initial) {
        return (
            <div className={cn('rounded-xl border border-border p-4', className)}>
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 className="size-4 animate-spin" />
                    Loading eBay listings…
                </div>
            </div>
        );
    }

    const listings = data?.listings ?? [];
    const options = data?.ebay_options ?? [];
    const groups = groupOptions(options);

    return (
        <section className={cn('rounded-xl border border-border', className)}>
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
                <div>
                    <h2 className="text-sm font-semibold tracking-tight">
                        Available on eBay
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Live buy-it-now listings
                        {option ? ` · ${option}` : ''}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {options.length > 0 && (
                        <Select value={option} onValueChange={changeOption}>
                            <SelectTrigger
                                className="h-9 w-[11.5rem]"
                                aria-label="Condition or grade"
                            >
                                <SelectValue placeholder="Condition / grade" />
                            </SelectTrigger>
                            <SelectContent>
                                {groups.map(([group, opts]) => (
                                    <SelectGroup key={group}>
                                        <SelectLabel>{group}</SelectLabel>
                                        {opts.map((o) => (
                                            <SelectItem
                                                key={o.label}
                                                value={o.label}
                                            >
                                                {o.label}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    {shopUrl && (
                        <Button asChild size="sm" variant="default">
                            <a href={shopUrl} target="_blank" rel={REL}>
                                <ShoppingCart className="size-4" />
                                Shop on eBay
                                <ExternalLink className="size-3.5 opacity-70" />
                            </a>
                        </Button>
                    )}
                </div>
            </div>

            <div className="relative px-2 py-1 sm:px-3">
                {loading && (
                    <div className="absolute inset-0 z-10 flex items-center justify-center rounded-b-xl bg-background/60 backdrop-blur-[1px]">
                        <Loader2 className="size-5 animate-spin text-muted-foreground" />
                    </div>
                )}

                {listings.length > 0 ? (
                    <EbayListings listings={listings} />
                ) : (
                    <div className="px-2 py-8 text-center text-sm text-muted-foreground">
                        {data?.configured === false
                            ? 'Live listings unavailable right now.'
                            : `No active ${option} listings found.`}
                        {shopUrl && (
                            <div className="mt-3">
                                <Button asChild variant="outline" size="sm">
                                    <a href={shopUrl} target="_blank" rel={REL}>
                                        Search eBay for {option}
                                    </a>
                                </Button>
                            </div>
                        )}
                    </div>
                )}
            </div>

            <p className="border-t border-border px-4 py-2 text-[11px] text-muted-foreground">
                As an eBay Partner, we may be compensated for qualifying purchases.
            </p>
        </section>
    );
}

/** Compact list of live eBay "buy it now" listings (affiliate-linked). */
export function EbayListings({ listings }: { listings: EbayListing[] }) {
    if (listings.length === 0) {
        return null;
    }

    return (
        <ul className="divide-y divide-border">
            {listings.map((l, i) => (
                <li key={i}>
                    <a
                        href={l.url}
                        target="_blank"
                        rel={REL}
                        className="flex items-center gap-3 px-2 py-2.5 transition-opacity hover:bg-accent/40 hover:opacity-100 sm:px-1"
                    >
                        {l.image ? (
                            <img
                                src={l.image}
                                alt=""
                                className="size-12 shrink-0 rounded object-contain sm:size-14"
                                loading="lazy"
                            />
                        ) : (
                            <div className="size-12 shrink-0 rounded bg-muted sm:size-14" />
                        )}
                        <span className="min-w-0 flex-1">
                            <span className="line-clamp-2 text-sm">
                                {l.title}
                            </span>
                            {l.condition && (
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                    {l.condition}
                                </span>
                            )}
                        </span>
                        <span className="text-sm font-semibold whitespace-nowrap">
                            {formatMoney(l.price_cents)}
                        </span>
                    </a>
                </li>
            ))}
        </ul>
    );
}

function groupOptions(options: EbayOption[]): [string, EbayOption[]][] {
    const map = new Map<string, EbayOption[]>();

    for (const o of options) {
        const list = map.get(o.group) ?? [];
        list.push(o);
        map.set(o.group, list);
    }

    return Array.from(map.entries());
}
