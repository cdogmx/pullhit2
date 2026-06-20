import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Heart,
    Home,
    LibraryBig,
    Package,
    Plus,
    ScanLine,
    Search,
    Sparkles,
    TrendingDown,
    TrendingUp,
    Wallet,
    Zap,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cardHref, formatMoney, relativeTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

type PortfolioSummary = {
    total_value: number;
    total_cost: number;
    cost_basis_valued: number;
    unrealized_gain: number;
    unrealized_pct: number | null;
    item_count: number;
    card_count: number;
    valued_count: number;
    currency: string;
};

type Mover = {
    id: number;
    name: string | null;
    number: string | null;
    state: string;
    gain: number | null;
    pct: number | null;
};

type Allocation = {
    label: string;
    brand: string | null;
    value: number;
    pct: number;
};

type Scans = {
    used: number;
    cap: number | null;
    remaining: number | null;
    monthly_remaining: number | null;
    credits: number;
    unlimited: boolean;
};

type RecentItem = {
    id: number;
    catalog_item_id: number | null;
    url?: string | null;
    name: string | null;
    number: string | null;
    set: string | null;
    image_url: string | null;
    state: string;
    quantity: number;
    market_value: number | null;
    added_at: string | null;
};

type Props = {
    user: {
        name: string;
        tier: string;
        tier_label: string;
        is_admin: boolean;
    };
    portfolio: PortfolioSummary;
    movers: { gainers: Mover[]; decliners: Mover[] };
    allocation: Allocation[];
    scans: Scans;
    wishlist: { item_count: number; below_target: number };
    recent: RecentItem[];
    counts: { collections: number; wishlists: number };
};

function gainColor(n: number): string {
    if (n > 0) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (n < 0) {
        return 'text-red-600 dark:text-red-400';
    }

    return 'text-muted-foreground';
}

function signedMoney(cents: number, currency = 'USD'): string {
    const sign = cents > 0 ? '+' : cents < 0 ? '-' : '';

    return `${sign}${formatMoney(Math.abs(cents), currency)}`;
}

export default function Dashboard({
    user,
    portfolio,
    movers,
    allocation,
    scans,
    wishlist,
    recent,
    counts,
}: Props) {
    const c = portfolio.currency;
    const isEmpty = portfolio.item_count === 0;
    const firstName = user.name.split(' ')[0];
    const isFree = !user.is_admin && user.tier === 'free';

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                {/* Greeting */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <Link
                            href="/"
                            className="inline-flex items-center gap-1 text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        >
                            <Home className="size-3.5" />
                            Back to homepage
                        </Link>
                        <h1 className="mt-1 text-xl font-bold tracking-tight">
                            Welcome back, {firstName}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Here's how your collection is doing.
                        </p>
                    </div>
                    <Badge
                        variant={user.is_admin || !isFree ? 'default' : 'secondary'}
                        className="capitalize"
                    >
                        {user.tier_label} plan
                    </Badge>
                </div>

                {isEmpty ? (
                    <EmptyState />
                ) : (
                    <>
                        {/* Stat cards */}
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <StatCard
                                icon={Wallet}
                                label="Portfolio value"
                                value={formatMoney(portfolio.total_value, c)}
                                foot={
                                    <span
                                        className={cn(
                                            'font-medium',
                                            gainColor(portfolio.unrealized_gain),
                                        )}
                                    >
                                        {signedMoney(
                                            portfolio.unrealized_gain,
                                            c,
                                        )}
                                        {portfolio.unrealized_pct !== null &&
                                            ` (${portfolio.unrealized_pct > 0 ? '+' : ''}${portfolio.unrealized_pct}%)`}
                                    </span>
                                }
                                href="/collection"
                            />
                            <StatCard
                                icon={Package}
                                label="Cards"
                                value={portfolio.card_count.toLocaleString()}
                                foot={`${portfolio.item_count.toLocaleString()} holdings · ${portfolio.valued_count.toLocaleString()} valued`}
                                href="/collection"
                            />
                            <StatCard
                                icon={Heart}
                                label="Wishlist"
                                value={wishlist.item_count.toLocaleString()}
                                foot={
                                    wishlist.below_target > 0 ? (
                                        <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                            {wishlist.below_target} at/below
                                            target
                                        </span>
                                    ) : (
                                        'No price alerts'
                                    )
                                }
                                href="/wishlist"
                            />
                            <StatCard
                                icon={ScanLine}
                                label="Scans this month"
                                value={
                                    scans.unlimited
                                        ? '∞'
                                        : `${scans.monthly_remaining?.toLocaleString() ?? 0} left`
                                }
                                foot={
                                    scans.unlimited
                                        ? 'Unlimited'
                                        : `${scans.used.toLocaleString()}/${scans.cap?.toLocaleString()} used${scans.credits > 0 ? ` · ${scans.credits.toLocaleString()} credits` : ''}`
                                }
                                href="/scan"
                            />
                        </div>

                        {/* Movers + allocation */}
                        <div className="grid gap-4 lg:grid-cols-3">
                            <Card className="lg:col-span-2">
                                <CardContent className="pt-6">
                                    <h2 className="mb-3 text-sm font-semibold">
                                        Top movers
                                    </h2>
                                    {movers.gainers.length === 0 &&
                                    movers.decliners.length === 0 ? (
                                        <p className="py-6 text-center text-sm text-muted-foreground">
                                            Not enough price history yet — check
                                            back soon.
                                        </p>
                                    ) : (
                                        <div className="grid gap-x-8 gap-y-1 sm:grid-cols-2">
                                            <MoverList
                                                title="Gainers"
                                                items={movers.gainers}
                                                currency={c}
                                            />
                                            <MoverList
                                                title="Decliners"
                                                items={movers.decliners}
                                                currency={c}
                                            />
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent className="pt-6">
                                    <h2 className="mb-3 text-sm font-semibold">
                                        Allocation by set
                                    </h2>
                                    {allocation.length === 0 ? (
                                        <p className="py-6 text-center text-sm text-muted-foreground">
                                            No valued cards yet.
                                        </p>
                                    ) : (
                                        <div className="max-h-72 space-y-2.5 overflow-y-auto">
                                            {allocation.map((a) => (
                                                <div key={a.label}>
                                                    <div className="flex items-baseline justify-between text-sm">
                                                        <span className="min-w-0 truncate pr-2 font-medium">
                                                            {a.brand && (
                                                                <span className="font-normal text-muted-foreground">
                                                                    {a.brand}{' '}
                                                                    <span aria-hidden>
                                                                        →
                                                                    </span>{' '}
                                                                </span>
                                                            )}
                                                            {a.label}
                                                        </span>
                                                        <span className="shrink-0 text-xs text-muted-foreground">
                                                            {formatMoney(
                                                                a.value,
                                                                c,
                                                            )}{' '}
                                                            · {a.pct}%
                                                        </span>
                                                    </div>
                                                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className="h-full rounded-full bg-primary"
                                                            style={{
                                                                width: `${Math.max(2, a.pct)}%`,
                                                            }}
                                                        />
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* Recent additions */}
                        {recent.length > 0 && (
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="mb-3 flex items-center justify-between">
                                        <h2 className="text-sm font-semibold">
                                            Recently added
                                        </h2>
                                        <Link
                                            href="/collection"
                                            className="text-xs font-medium text-primary hover:underline"
                                        >
                                            View collection →
                                        </Link>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                                        {recent.map((r) => (
                                            <RecentCard
                                                key={r.id}
                                                item={r}
                                                currency={c}
                                            />
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </>
                )}

                {/* Quick actions */}
                <QuickActions
                    isFree={isFree}
                    collections={counts.collections}
                    wishlists={counts.wishlists}
                />
            </div>
        </>
    );
}

function StatCard({
    icon: Icon,
    label,
    value,
    foot,
    href,
}: {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    value: string;
    foot: React.ReactNode;
    href: string;
}) {
    return (
        <Link href={href} className="group">
            <Card className="transition-colors group-hover:border-primary/40">
                <CardContent className="pt-6">
                    <div className="flex items-center justify-between">
                        <p className="text-xs text-muted-foreground">{label}</p>
                        <Icon className="size-4 text-muted-foreground" />
                    </div>
                    <p className="mt-1 text-2xl font-bold tracking-tight">
                        {value}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {foot}
                    </p>
                </CardContent>
            </Card>
        </Link>
    );
}

function MoverList({
    title,
    items,
    currency,
}: {
    title: string;
    items: Mover[];
    currency: string;
}) {
    const up = title === 'Gainers';

    return (
        <div>
            <p className="mb-1 flex items-center gap-1 text-xs font-medium text-muted-foreground">
                {up ? (
                    <TrendingUp className="size-3.5 text-emerald-500" />
                ) : (
                    <TrendingDown className="size-3.5 text-red-500" />
                )}
                {title}
            </p>
            {items.length === 0 ? (
                <p className="py-2 text-xs text-muted-foreground">None</p>
            ) : (
                <ul className="divide-y divide-border/60">
                    {items.map((m) => (
                        <li
                            key={m.id}
                            className="flex items-center justify-between gap-2 py-1.5 text-sm"
                        >
                            <span className="min-w-0 truncate">
                                {m.name}
                                {m.number && (
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        {m.number}
                                    </span>
                                )}
                            </span>
                            <span
                                className={cn(
                                    'shrink-0 text-xs font-medium',
                                    gainColor(m.gain ?? 0),
                                )}
                            >
                                {m.gain !== null && signedMoney(m.gain, currency)}
                                {m.pct !== null &&
                                    ` (${m.pct > 0 ? '+' : ''}${m.pct}%)`}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function RecentCard({
    item,
    currency,
}: {
    item: RecentItem;
    currency: string;
}) {
    const body = (
        <>
            <div className="aspect-[3/4] overflow-hidden rounded-md bg-muted">
                {item.image_url ? (
                    <img
                        src={item.image_url}
                        alt={item.name ?? ''}
                        className="size-full object-contain"
                        loading="lazy"
                    />
                ) : (
                    <div className="flex size-full items-center justify-center">
                        <Package className="size-6 text-muted-foreground/40" />
                    </div>
                )}
            </div>
            <p className="mt-1.5 truncate text-xs font-medium">{item.name}</p>
            <p className="flex items-center justify-between text-[11px] text-muted-foreground">
                <span>{formatMoney(item.market_value, currency)}</span>
                <span>{relativeTime(item.added_at)}</span>
            </p>
        </>
    );

    return item.catalog_item_id ? (
        <Link href={cardHref(item)} className="group block">
            {body}
        </Link>
    ) : (
        <div>{body}</div>
    );
}

const ACTIONS = [
    {
        href: '/scan',
        icon: ScanLine,
        title: 'Scan a card',
        desc: 'Identify with AI',
    },
    {
        href: '/browse',
        icon: Search,
        title: 'Browse catalog',
        desc: 'Find prices',
    },
    {
        href: '/collection/import',
        icon: Plus,
        title: 'Import collection',
        desc: 'From a CSV',
    },
];

function QuickActions({
    isFree,
    collections,
    wishlists,
}: {
    isFree: boolean;
    collections: number;
    wishlists: number;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {ACTIONS.map((a) => (
                <Link key={a.href} href={a.href} className="group">
                    <Card className="transition-colors group-hover:border-primary/40">
                        <CardContent className="flex items-center gap-3 py-4">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <a.icon className="size-5" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-sm font-semibold">
                                    {a.title}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {a.desc}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </Link>
            ))}

            {isFree ? (
                <Link href="/settings/billing" className="group">
                    <Card className="border-primary/30 bg-primary/5 transition-colors group-hover:bg-primary/10">
                        <CardContent className="flex items-center gap-3 py-4">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/15 text-primary">
                                <Zap className="size-5" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-sm font-semibold">Upgrade</p>
                                <p className="truncate text-xs text-muted-foreground">
                                    More scans &amp; lists
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </Link>
            ) : (
                <Card>
                    <CardContent className="flex items-center gap-3 py-4">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                            <LibraryBig className="size-5" />
                        </span>
                        <div className="min-w-0">
                            <p className="text-sm font-semibold">
                                {collections.toLocaleString()} collection
                                {collections === 1 ? '' : 's'}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {wishlists.toLocaleString()} wishlist
                                {wishlists === 1 ? '' : 's'}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

function EmptyState() {
    return (
        <Card>
            <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                <span className="flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                    <Sparkles className="size-7" />
                </span>
                <div className="max-w-md">
                    <h2 className="text-lg font-semibold">
                        Start your collection
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Scan a card with AI, browse the catalog, or import an
                        existing collection. Your portfolio value and price
                        alerts will show up here.
                    </p>
                </div>
                <div className="flex flex-wrap justify-center gap-2">
                    <Button asChild>
                        <Link href="/scan">
                            <ScanLine className="size-4" /> Scan a card
                        </Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href="/browse">
                            <Search className="size-4" /> Browse catalog
                        </Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href="/collection/import">
                            Import <ArrowRight className="size-4" />
                        </Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
