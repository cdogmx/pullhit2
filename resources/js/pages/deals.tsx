import { Head } from '@inertiajs/react';
import { ExternalLink, PackageOpen, Tag } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';

type Offer = {
    retailer: string;
    price: number | null;
    url: string;
};

type Deal = {
    name: string;
    image: string | null;
    catalog_name: string | null;
    currency: string;
    target_price: number;
    last_seen: string | null;
    offers: Offer[];
};

type RecentAlert = {
    name: string;
    image: string | null;
    retailer: string;
    price: number | null;
    currency: string;
    url: string;
    tweeted_at: string;
};

type Props = {
    deals: Deal[];
    recent: RecentAlert[];
    seo: { title: string; heading: string };
};

const money = (value: number | null, currency: string): string => {
    if (value === null) {
        return '—';
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
        }).format(value);
    } catch {
        return `${value} ${currency}`;
    }
};

const ago = (iso: string | null): string => {
    if (!iso) {
        return '';
    }

    const secs = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (secs < 60) {
        return 'just now';
    }

    if (secs < 3600) {
        return `${Math.round(secs / 60)}m ago`;
    }

    if (secs < 86400) {
        return `${Math.round(secs / 3600)}h ago`;
    }

    return `${Math.round(secs / 86400)}d ago`;
};

export default function Deals({ deals, recent, seo }: Props) {
    return (
        <>
            <Head title={seo.title} />

            <div className="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight sm:text-3xl">
                        <Tag className="size-6 text-primary" />
                        {seo.heading}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Products we’re watching that are in stock at or below
                        our target price. Prices and availability change fast —
                        tap through to the retailer to confirm.
                    </p>
                </div>

                {deals.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
                            <PackageOpen className="size-8 text-muted-foreground" />
                            <p className="text-sm text-muted-foreground">
                                Nothing in stock at target right now. Check back
                                soon — we’re watching around the clock.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {deals.map((deal, i) => (
                            <Card key={i} className="overflow-hidden">
                                <CardContent className="flex gap-4 pt-6">
                                    <div className="size-20 shrink-0 overflow-hidden rounded-md border border-border bg-muted">
                                        {deal.image ? (
                                            <img
                                                src={deal.image}
                                                alt={deal.name}
                                                loading="lazy"
                                                className="size-full object-contain"
                                            />
                                        ) : (
                                            <div className="flex size-full items-center justify-center text-xs text-muted-foreground">
                                                No image
                                            </div>
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p
                                            className="truncate font-medium"
                                            title={deal.name}
                                        >
                                            {deal.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            target{' '}
                                            {money(
                                                deal.target_price,
                                                deal.currency,
                                            )}
                                            {deal.last_seen
                                                ? ` · seen ${ago(deal.last_seen)}`
                                                : ''}
                                        </p>
                                        <div className="mt-2 flex flex-col gap-1.5">
                                            {deal.offers.map((offer, j) => (
                                                <a
                                                    key={j}
                                                    href={offer.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer nofollow"
                                                    className="flex items-center justify-between gap-2 rounded-md border border-border px-2.5 py-1.5 text-sm transition-colors hover:border-ring hover:bg-accent/40"
                                                >
                                                    <span className="inline-flex items-center gap-1.5 font-medium">
                                                        {offer.retailer}
                                                        <ExternalLink className="size-3 text-muted-foreground" />
                                                    </span>
                                                    <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                                                        {money(
                                                            offer.price,
                                                            deal.currency,
                                                        )}
                                                    </span>
                                                </a>
                                            ))}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {recent.length > 0 && (
                    <div className="mt-10">
                        <h2 className="mb-3 text-lg font-semibold">
                            Recent alerts
                        </h2>
                        <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                            {recent.map((r, i) => (
                                <a
                                    key={i}
                                    href={r.url}
                                    target="_blank"
                                    rel="noopener noreferrer nofollow"
                                    className="flex items-center gap-3 bg-card px-3 py-2.5 transition-colors hover:bg-accent/40"
                                >
                                    <div className="size-10 shrink-0 overflow-hidden rounded bg-muted">
                                        {r.image && (
                                            <img
                                                src={r.image}
                                                alt=""
                                                loading="lazy"
                                                className="size-full object-contain"
                                            />
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {r.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            <Badge
                                                variant="secondary"
                                                className="mr-1 text-[10px]"
                                            >
                                                {r.retailer}
                                            </Badge>
                                            {ago(r.tweeted_at)}
                                        </p>
                                    </div>
                                    <span className="shrink-0 text-sm font-semibold">
                                        {money(r.price, r.currency)}
                                    </span>
                                </a>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}
