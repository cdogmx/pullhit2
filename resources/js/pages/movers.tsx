import { Head, Link } from '@inertiajs/react';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Mover = {
    name: string;
    number: string | null;
    set: string | null;
    href: string | null;
    image: string | null;
    value: number | null;
    /** 30-day change as a percent (e.g. 15 = +15%). */
    trend: number;
    /** Dollar change over the window, in cents (signed). */
    change: number | null;
};

type Line = { slug: string; name: string };

type Props = {
    gainers: Mover[];
    losers: Mover[];
    lines: Line[];
    line: string | null;
    meta: { title: string; description: string };
};

export default function Movers({ gainers, losers, lines, line, meta }: Props) {
    return (
        <>
            <Head title="Biggest movers">
                <meta name="description" content={meta.description} />
            </Head>

            <div className="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        Biggest movers
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        The largest 30-day swings on real sold-price data —
                        ungraded values with at least a few recent sales.
                    </p>
                </div>

                {/* Product-line filter */}
                {lines.length > 1 && (
                    <div className="mb-6 flex flex-wrap gap-2">
                        <FilterChip label="All games" href="/movers" active={!line} />
                        {lines.map((l) => (
                            <FilterChip
                                key={l.slug}
                                label={l.name}
                                href={`/movers?line=${l.slug}`}
                                active={line === l.slug}
                            />
                        ))}
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <MoverColumn
                        title="Biggest gainers"
                        direction="up"
                        movers={gainers}
                    />
                    <MoverColumn
                        title="Biggest losers"
                        direction="down"
                        movers={losers}
                    />
                </div>
            </div>
        </>
    );
}

function FilterChip({
    label,
    href,
    active,
}: {
    label: string;
    href: string;
    active: boolean;
}) {
    return (
        <Link
            href={href}
            preserveScroll
            className={cn(
                'rounded-full border px-3 py-1 text-sm font-medium transition-colors',
                active
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'border-border text-muted-foreground hover:text-foreground',
            )}
        >
            {label}
        </Link>
    );
}

function MoverColumn({
    title,
    direction,
    movers,
}: {
    title: string;
    direction: 'up' | 'down';
    movers: Mover[];
}) {
    const up = direction === 'up';
    const Icon = up ? TrendingUp : TrendingDown;

    return (
        <section className="rounded-xl border border-border bg-card">
            <div className="flex items-center gap-2 border-b border-border px-4 py-3">
                <Icon
                    className={cn(
                        'size-5',
                        up
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : 'text-red-600 dark:text-red-400',
                    )}
                />
                <h2 className="text-sm font-semibold tracking-wide uppercase">
                    {title}
                </h2>
            </div>

            {movers.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                    No movers to show yet.
                </p>
            ) : (
                <ol className="divide-y divide-border">
                    {movers.map((m, i) => (
                        <MoverRow key={m.href ?? i} rank={i + 1} mover={m} />
                    ))}
                </ol>
            )}
        </section>
    );
}

function MoverRow({ rank, mover }: { rank: number; mover: Mover }) {
    const up = mover.trend > 0;
    const trendColor = up
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-red-600 dark:text-red-400';

    const inner = (
        <>
            <span className="w-5 shrink-0 text-right text-xs font-medium text-muted-foreground tabular-nums">
                {rank}
            </span>
            <div className="aspect-[5/7] w-10 shrink-0 overflow-hidden rounded border border-border bg-muted">
                {mover.image && (
                    <img
                        src={mover.image}
                        alt={mover.name}
                        loading="lazy"
                        className="size-full object-cover"
                    />
                )}
            </div>
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{mover.name}</p>
                <p className="truncate text-xs text-muted-foreground">
                    {mover.set}
                    {mover.number ? ` · ${mover.number}` : ''}
                </p>
            </div>
            <div className="shrink-0 text-right">
                <p className="text-sm font-semibold tabular-nums">
                    {mover.value != null ? formatMoney(mover.value) : '—'}
                </p>
                <p className={cn('text-xs font-medium tabular-nums', trendColor)}>
                    {up ? '↑' : '↓'}
                    {Math.abs(mover.trend)}%
                    {mover.change != null && (
                        <span className="ml-1 opacity-80">
                            ({up ? '+' : '−'}
                            {formatMoney(Math.abs(mover.change))})
                        </span>
                    )}
                </p>
            </div>
        </>
    );

    return mover.href ? (
        <li>
            <Link
                href={mover.href}
                className="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-accent/50"
            >
                {inner}
            </Link>
        </li>
    ) : (
        <li className="flex items-center gap-3 px-4 py-2.5">{inner}</li>
    );
}
