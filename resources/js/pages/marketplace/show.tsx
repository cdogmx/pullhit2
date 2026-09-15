import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ImageOff,
    MessageCircle,
    Pencil,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Photo = { id: number; path: string };

type Listing = {
    id: number;
    title: string;
    description: string | null;
    category_label: string;
    price_cents: number;
    currency: string;
    accepts_offers: boolean;
    accepts_direct: boolean;
    accepts_escrow: boolean;
    photos: Photo[];
    grade: string | null;
    grader: string | null;
    condition: string | null;
    cert_number: string | null;
    set_code: string | null;
    card_number: string | null;
    status: string;
    status_label: string;
    seller: string | null;
    card: {
        name: string;
        number: string | null;
        set: string | null;
        url: string | null;
    } | null;
    market: {
        cents: number;
        state: string;
        sales: number;
        confidence: number;
    } | null;
};

type Props = {
    listing: Listing;
    canEdit: boolean;
    certConflicts: { id: number; seller: string | null; url: string }[];
};

export default function MarketplaceShow({
    listing,
    canEdit,
    certConflicts,
}: Props) {
    const [active, setActive] = useState(0);
    const photo = listing.photos[active];

    return (
        <>
            <Head title={listing.title} />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4">
                <div className="grid gap-6 md:grid-cols-2">
                    {/* Photos */}
                    <div className="flex flex-col gap-3">
                        <div className="relative aspect-[5/7] overflow-hidden rounded-xl border border-border bg-muted">
                            {photo ? (
                                <img
                                    src={photo.path}
                                    alt={listing.title}
                                    className="size-full object-contain"
                                />
                            ) : (
                                <div className="flex size-full items-center justify-center text-muted-foreground">
                                    <ImageOff className="size-8" />
                                </div>
                            )}
                        </div>

                        {listing.photos.length > 1 && (
                            <ul className="flex flex-wrap gap-2">
                                {listing.photos.map((p, i) => (
                                    <li key={p.id}>
                                        <button
                                            type="button"
                                            onClick={() => setActive(i)}
                                            className={cn(
                                                'size-14 overflow-hidden rounded-md border',
                                                i === active
                                                    ? 'border-foreground'
                                                    : 'border-border opacity-70 hover:opacity-100',
                                            )}
                                        >
                                            <img
                                                src={p.path}
                                                alt=""
                                                className="size-full object-cover"
                                            />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {/* Detail */}
                    <div className="flex flex-col gap-4">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline">
                                    {listing.category_label}
                                </Badge>
                                {listing.grade && (
                                    <Badge className="uppercase">
                                        {listing.grader} {listing.grade}
                                    </Badge>
                                )}
                                {listing.condition && (
                                    <Badge variant="outline">
                                        {listing.condition}
                                    </Badge>
                                )}
                                {listing.status !== 'active' && (
                                    <Badge variant="secondary">
                                        {listing.status_label}
                                    </Badge>
                                )}
                            </div>
                            <h1 className="mt-2 text-2xl font-bold tracking-tight">
                                {listing.title}
                            </h1>
                            {listing.seller && (
                                <p className="text-sm text-muted-foreground">
                                    Listed by @{listing.seller}
                                </p>
                            )}
                        </div>

                        <div>
                            <p className="text-3xl font-semibold tabular-nums">
                                {formatMoney(
                                    listing.price_cents,
                                    listing.currency,
                                )}
                                {listing.accepts_offers && (
                                    <span className="ml-2 align-middle text-sm font-normal text-muted-foreground">
                                        or best offer
                                    </span>
                                )}
                            </p>

                            {/* The thing an eBay listing cannot show you: what
                                this card actually sells for, beside what is
                                being asked. Only when the seller linked a card. */}
                            {listing.market && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Market {listing.market.state}:{' '}
                                    <span className="font-medium text-foreground tabular-nums">
                                        {formatMoney(listing.market.cents)}
                                    </span>
                                    {listing.market.cents > 0 && (
                                        <>
                                            {' · '}
                                            {(() => {
                                                const diff =
                                                    ((listing.price_cents -
                                                        listing.market.cents) /
                                                        listing.market.cents) *
                                                    100;

                                                return (
                                                    <span
                                                        className={cn(
                                                            Math.abs(diff) < 10
                                                                ? ''
                                                                : diff > 0
                                                                  ? 'text-amber-600 dark:text-amber-500'
                                                                  : 'text-emerald-600 dark:text-emerald-400',
                                                        )}
                                                    >
                                                        {diff > 0 ? '+' : ''}
                                                        {diff.toFixed(0)}%
                                                    </span>
                                                );
                                            })()}
                                        </>
                                    )}
                                    {/* A thin number should read as thin, not
                                        as fact — the same honesty the card
                                        pages use. */}
                                    {listing.market.sales > 0 && (
                                        <span className="ml-1">
                                            ({listing.market.sales} sale
                                            {listing.market.sales === 1
                                                ? ''
                                                : 's'}
                                            )
                                        </span>
                                    )}
                                </p>
                            )}
                        </div>

                        {/* Two ways to buy. Protected is primary: it is the only
                            path where anything stands behind the transaction. */}
                        <div className="flex flex-col gap-2">
                            {listing.accepts_escrow && (
                                <Button size="lg" className="gap-2">
                                    <ShieldCheck className="size-4" />
                                    Buy Protected —{' '}
                                    {formatMoney(
                                        listing.price_cents,
                                        listing.currency,
                                    )}
                                </Button>
                            )}
                            {listing.accepts_direct && (
                                <Button
                                    size="lg"
                                    variant={
                                        listing.accepts_escrow
                                            ? 'outline'
                                            : 'default'
                                    }
                                    className="gap-2"
                                    onClick={() =>
                                        router.post(
                                            `/marketplace/${listing.id}/contact`,
                                        )
                                    }
                                >
                                    <MessageCircle className="size-4" />
                                    Contact seller
                                </Button>
                            )}
                            {canEdit && (
                                <Button
                                    variant="ghost"
                                    className="gap-2"
                                    onClick={() =>
                                        router.get(
                                            `/marketplace/${listing.id}/edit`,
                                        )
                                    }
                                >
                                    <Pencil className="size-4" />
                                    Edit listing
                                </Button>
                            )}
                        </div>

                        {listing.accepts_escrow && (
                            <p className="text-xs text-muted-foreground">
                                Buy Protected holds your payment until the card
                                arrives and you have had a chance to check it.
                            </p>
                        )}

                        {/* The loudest scam signal this marketplace can make:
                            two people cannot both hold one slab. */}
                        {certConflicts.length > 0 && (
                            <div className="flex gap-3 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-500" />
                                <div className="text-sm">
                                    <p className="font-medium">
                                        This certificate number is listed{' '}
                                        {certConflicts.length === 1
                                            ? 'somewhere else'
                                            : `${certConflicts.length} other times`}{' '}
                                        right now.
                                    </p>
                                    <p className="text-muted-foreground">
                                        Only one person can hold a given slab.
                                        Check the cert on the grader's site
                                        before paying.
                                    </p>
                                    <ul className="mt-1 flex flex-wrap gap-2">
                                        {certConflicts.map((c) => (
                                            <li key={c.id}>
                                                <Link
                                                    href={c.url}
                                                    className="underline underline-offset-2"
                                                >
                                                    @{c.seller ?? 'seller'}
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </div>
                        )}

                        {listing.description && (
                            <p className="text-sm whitespace-pre-line text-muted-foreground">
                                {listing.description}
                            </p>
                        )}

                        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                            {listing.card?.url && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Card
                                    </dt>
                                    <dd>
                                        <Link
                                            href={listing.card.url}
                                            className="underline underline-offset-2"
                                        >
                                            {listing.card.name}
                                        </Link>
                                    </dd>
                                </>
                            )}
                            {listing.card?.set && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Set
                                    </dt>
                                    <dd>{listing.card.set}</dd>
                                </>
                            )}
                            {listing.card_number && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Number
                                    </dt>
                                    <dd className="tabular-nums">
                                        {listing.card_number}
                                    </dd>
                                </>
                            )}
                            {listing.cert_number && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Cert
                                    </dt>
                                    <dd className="tabular-nums">
                                        {listing.cert_number}
                                    </dd>
                                </>
                            )}
                        </dl>
                    </div>
                </div>

                <p className="rounded-lg border border-border bg-muted/40 p-3 text-xs text-muted-foreground">
                    CardFoo is a venue only and is not party to this
                    transaction. Never pay a stranger by Friends &amp; Family —
                    it has no buyer protection. For protection, use Buy
                    Protected.
                </p>
            </div>
        </>
    );
}
