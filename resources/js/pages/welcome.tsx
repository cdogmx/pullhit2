import { Head, Link, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    Boxes,
    Gift,
    LineChart,
    ScanLine,
    Sparkles,
    Store,
    TrendingDown,
    TrendingUp,
    Trophy,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { HScroller } from '@/components/shared/h-scroller';
import { SiteSearch } from '@/components/site-search';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard, register } from '@/routes';

type CardTileData = {
    name: string;
    number: string | null;
    set: string | null;
    href: string | null;
    image: string | null;
    value: number | null;
    estimated: boolean;
    trend: number | null;
    state?: string;
    updated_at?: string;
};

type SetTileData = {
    name: string;
    code: string | null;
    logo: string | null;
    released: string | null;
    count: number;
    href: string | null;
};

type Brand = {
    slug: string;
    name: string;
    logo: string | null;
    count: number;
    href: string;
};

type Community = {
    points: {
        edit_suggestion: number;
        missing_card: number;
        missing_set: number;
    };
    levels: { name: string; min: number }[];
    month: string;
};

type Props = {
    brands: Brand[];
    trending: CardTileData[];
    movers: CardTileData[];
    recent: CardTileData[];
    popularSets: SetTileData[];
    community: Community;
};

const features: { title: string; description: string; icon: LucideIcon }[] = [
    {
        title: 'Distributions, not points',
        description:
            'Every value ships as median, range, sample size, and a confidence score — never a lone number.',
        icon: LineChart,
    },
    {
        title: 'Scan to add',
        description:
            'Identify cards by set, number, and language. You confirm the exact variant before anything is added.',
        icon: ScanLine,
    },
    {
        title: 'Graded & raw',
        description:
            'Price sealed product, raw singles by condition, and graded items by service and grade.',
        icon: BadgeCheck,
    },
    {
        title: 'First-party marketplace',
        description:
            'List from your collection and sell to other collectors, with cost-basis tracking built in.',
        icon: Store,
    },
];

const popularSearches = ['Charizard', 'Pikachu', 'PSA 10', 'Booster box'];

function TrendBadge({ trend }: { trend: number }) {
    const up = trend > 0;
    const Icon = up ? TrendingUp : TrendingDown;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 text-xs font-semibold',
                up
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-red-600 dark:text-red-400',
            )}
        >
            <Icon className="size-3" />
            {Math.abs(trend).toFixed(0)}%
        </span>
    );
}

function CardTile({ card }: { card: CardTileData }) {
    const inner = (
        <div className="w-36 shrink-0 snap-start sm:w-40">
            <div className="aspect-[5/7] overflow-hidden rounded-lg border border-border bg-muted">
                {card.image && (
                    <img
                        src={card.image}
                        alt={card.name}
                        loading="lazy"
                        className="size-full object-cover"
                    />
                )}
            </div>
            <p className="mt-2 truncate text-sm font-medium">{card.name}</p>
            <p className="truncate text-xs text-muted-foreground">
                {card.set}
                {card.number ? ` · ${card.number}` : ''}
            </p>
            <div className="mt-1 flex items-center gap-2">
                <span className="text-sm font-semibold">
                    {card.value != null ? formatMoney(card.value) : '—'}
                </span>
                {card.trend != null && card.trend !== 0 && (
                    <TrendBadge trend={card.trend} />
                )}
            </div>
        </div>
    );

    return card.href ? (
        <Link href={card.href} className="block">
            {inner}
        </Link>
    ) : (
        inner
    );
}

function SetTile({ set }: { set: SetTileData }) {
    const inner = (
        <div className="flex w-44 shrink-0 snap-start flex-col items-center gap-2 rounded-xl border border-border bg-card p-4 text-center transition-colors hover:border-primary/40">
            <div className="flex h-16 w-full items-center justify-center">
                {set.logo ? (
                    <img
                        src={set.logo}
                        alt={set.name}
                        loading="lazy"
                        className="max-h-16 max-w-full object-contain"
                    />
                ) : (
                    <span className="text-sm font-semibold">{set.name}</span>
                )}
            </div>
            <p className="line-clamp-2 text-xs font-medium">{set.name}</p>
            <p className="text-[11px] text-muted-foreground">
                {set.count.toLocaleString()} cards
                {set.released ? ` · ${set.released.slice(0, 4)}` : ''}
            </p>
        </div>
    );

    return set.href ? (
        <Link href={set.href} className="block">
            {inner}
        </Link>
    ) : (
        inner
    );
}

function Section({
    title,
    sub,
    href,
    cta,
    children,
}: {
    title: string;
    sub?: string;
    href?: string;
    cta?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <div className="mb-4 flex items-end justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold tracking-tight sm:text-2xl">
                        {title}
                    </h2>
                    {sub && (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {sub}
                        </p>
                    )}
                </div>
                {href && (
                    <Link
                        href={href}
                        className="shrink-0 text-sm font-semibold text-primary hover:underline"
                    >
                        {cta ?? 'See all'} &rarr;
                    </Link>
                )}
            </div>
            {children}
        </div>
    );
}

export default function Welcome({
    brands,
    trending,
    movers,
    recent,
    popularSets,
    community,
}: Props) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Trading card prices, values & collection tracker" />

            {/* Hero */}
            <section className="relative overflow-hidden bg-primary text-primary-foreground">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-y-0 left-0 hidden w-40 md:block lg:w-60"
                    style={{
                        backgroundImage:
                            'radial-gradient(rgba(17,19,23,0.16) 1.5px, transparent 1.6px)',
                        backgroundSize: '18px 18px',
                        maskImage:
                            'linear-gradient(to right, black, transparent)',
                        WebkitMaskImage:
                            'linear-gradient(to right, black, transparent)',
                    }}
                />
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-y-0 right-0 hidden w-40 md:block lg:w-60"
                    style={{
                        backgroundImage:
                            'radial-gradient(rgba(17,19,23,0.16) 1.5px, transparent 1.6px)',
                        backgroundSize: '18px 18px',
                        maskImage:
                            'linear-gradient(to left, black, transparent)',
                        WebkitMaskImage:
                            'linear-gradient(to left, black, transparent)',
                    }}
                />
                <div className="relative mx-auto w-full max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
                    <div className="mx-auto max-w-2xl text-center">
                        <span className="mx-auto mb-5 inline-flex items-center gap-2 rounded-full bg-black/10 px-3 py-1 text-xs font-semibold text-primary-foreground">
                            <span
                                className="size-1.5 rounded-full bg-current"
                                aria-hidden
                            />
                            Beta — values &amp; features are still improving
                        </span>
                        <AppLogoIcon className="mx-auto block size-24 fill-current sm:size-28" />
                        <h1 className="mt-2 font-script text-7xl leading-tight sm:text-8xl">
                            Wax on.
                        </h1>
                        <p className="mx-auto mt-5 max-w-xl text-lg font-medium text-primary-foreground/80">
                            Search any card and see what it&rsquo;s really worth
                            — sealed, raw, or graded, with a confidence score.
                        </p>

                        <SiteSearch
                            variant="hero"
                            placeholder="Search cards, sets, or players…"
                            className="mx-auto mt-8 w-full max-w-xl"
                        />

                        <div className="mt-4 flex flex-wrap items-center justify-center gap-2 text-sm">
                            <span className="text-primary-foreground/70">
                                Popular:
                            </span>
                            {popularSearches.map((term) => (
                                <Link
                                    key={term}
                                    href={`/browse?q=${encodeURIComponent(term)}`}
                                    className="rounded-full bg-black/10 px-3 py-1 font-medium transition-colors hover:bg-black/20"
                                >
                                    {term}
                                </Link>
                            ))}
                        </div>

                        <div className="mt-8">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="text-sm font-semibold underline-offset-4 hover:underline"
                                >
                                    Go to your dashboard &rarr;
                                </Link>
                            ) : (
                                <Link
                                    href={register()}
                                    className="text-sm font-semibold underline-offset-4 hover:underline"
                                >
                                    or start your collection &rarr;
                                </Link>
                            )}
                        </div>
                    </div>
                </div>
            </section>

            {/* Brand shortcuts */}
            {brands.length > 0 && (
                <section className="border-b border-border">
                    <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        <div className="flex flex-wrap items-center justify-center gap-3">
                            {brands.map((b) => (
                                <Link
                                    key={b.slug}
                                    href={b.href}
                                    className="flex items-center gap-2 rounded-full border border-border bg-card px-4 py-2 text-sm font-medium transition-colors hover:border-primary/50 hover:bg-primary/5"
                                >
                                    {b.logo ? (
                                        <img
                                            src={b.logo}
                                            alt=""
                                            className="size-5 object-contain"
                                        />
                                    ) : (
                                        <Boxes className="size-4 text-primary" />
                                    )}
                                    {b.name}
                                    <span className="text-xs text-muted-foreground">
                                        {b.count.toLocaleString()}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </div>
                </section>
            )}

            {/* Live catalog sections */}
            <section className="border-b border-border">
                <div className="mx-auto flex w-full max-w-7xl flex-col gap-14 px-4 py-14 sm:px-6 lg:px-8">
                    {trending.length > 0 && (
                        <Section
                            title="Trending cards"
                            sub="What collectors are looking at right now."
                            href="/browse"
                            cta="Browse all"
                        >
                            <HScroller>
                                {trending.map((c, i) => (
                                    <CardTile key={i} card={c} />
                                ))}
                            </HScroller>
                        </Section>
                    )}

                    {movers.length > 0 && (
                        <Section
                            title="Biggest movers"
                            sub="Largest 30-day swings on real sold-price data."
                        >
                            <HScroller>
                                {movers.map((c, i) => (
                                    <CardTile key={i} card={c} />
                                ))}
                            </HScroller>
                        </Section>
                    )}

                    {recent.length > 0 && (
                        <Section
                            title="Recently updated prices"
                            sub="Freshly repriced from new eBay sold comps."
                        >
                            <HScroller>
                                {recent.map((c, i) => (
                                    <CardTile key={i} card={c} />
                                ))}
                            </HScroller>
                        </Section>
                    )}

                    {popularSets.length > 0 && (
                        <Section
                            title="Popular sets"
                            sub="The most-viewed sets right now."
                            href="/browse"
                            cta="All sets"
                        >
                            <HScroller>
                                {popularSets.map((s, i) => (
                                    <SetTile key={i} set={s} />
                                ))}
                            </HScroller>
                        </Section>
                    )}
                </div>
            </section>

            {/* Points & giveaways */}
            <section className="border-b border-border bg-card/40">
                <div className="mx-auto w-full max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    <div className="grid gap-10 lg:grid-cols-2 lg:items-center">
                        <div>
                            <span className="inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                                <Sparkles className="size-3.5" />
                                Community
                            </span>
                            <h2 className="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">
                                Earn points. Win giveaways.
                            </h2>
                            <p className="mt-3 text-muted-foreground">
                                Help build the most accurate catalog anywhere.
                                Suggest a fix, or report a missing card or set —
                                every accepted contribution earns points, levels
                                you up, and counts as an entry into{' '}
                                <span className="font-semibold text-foreground">
                                    {community.month}
                                </span>
                                ’s giveaway.
                            </p>

                            <div className="mt-5 grid gap-2 sm:grid-cols-3">
                                {[
                                    {
                                        label: 'Suggest an edit',
                                        pts: community.points.edit_suggestion,
                                    },
                                    {
                                        label: 'Report a card',
                                        pts: community.points.missing_card,
                                    },
                                    {
                                        label: 'Report a set',
                                        pts: community.points.missing_set,
                                    },
                                ].map((row) => (
                                    <div
                                        key={row.label}
                                        className="rounded-lg border border-border bg-card p-3"
                                    >
                                        <p className="text-lg font-bold text-primary">
                                            +{row.pts}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {row.label}
                                        </p>
                                    </div>
                                ))}
                            </div>

                            <div className="mt-6 flex flex-wrap gap-3">
                                <Button asChild>
                                    <Link href="/contribute">
                                        Start contributing
                                    </Link>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href="/rankings">
                                        <Trophy className="size-4" />
                                        View rankings
                                    </Link>
                                </Button>
                            </div>
                        </div>

                        <div className="rounded-2xl border border-border bg-card p-6">
                            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Levels
                            </p>
                            <ol className="mt-3 space-y-1.5">
                                {community.levels.map((l, i) => (
                                    <li
                                        key={l.name}
                                        className="flex items-center gap-3"
                                    >
                                        <span className="flex size-6 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                                            {i + 1}
                                        </span>
                                        <span className="flex-1 text-sm font-medium">
                                            {l.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {l.min.toLocaleString()} pts
                                        </span>
                                    </li>
                                ))}
                            </ol>
                            <div className="mt-4 flex items-start gap-2 rounded-lg bg-primary/5 p-3 text-sm">
                                <Gift className="mt-0.5 size-4 shrink-0 text-primary" />
                                <span className="text-muted-foreground">
                                    Points earned this month are your entries in
                                    the {community.month} giveaway — the more you
                                    contribute, the better your odds.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* Why CardFoo */}
            <section
                id="features"
                className="scroll-mt-16 border-b border-border"
            >
                <div className="mx-auto w-full max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        {features.map((feature) => {
                            const Icon = feature.icon;

                            return (
                                <div
                                    key={feature.title}
                                    className="rounded-xl border border-border bg-card p-6"
                                >
                                    <span className="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <Icon className="size-5" />
                                    </span>
                                    <h3 className="mt-4 font-semibold">
                                        {feature.title}
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {feature.description}
                                    </p>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>
        </>
    );
}
