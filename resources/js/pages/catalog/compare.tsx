import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search, TrendingDown, TrendingUp, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CompareChart } from '@/components/charts/compare-chart';
import type {
    CompareMode,
    CompareSeries,
} from '@/components/charts/compare-chart';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PriceHistory, PricePoint } from '@/types';

type CompareItem = {
    id: number;
    name: string;
    set_name: string | null;
    url: string | null;
    image: string | null;
    latest: number | null;
    series: PriceHistory;
    /** Long-term monthly series (PriceCharting) for the "Max" window. */
    series_long: PricePoint[];
};

type Props = {
    items: CompareItem[];
    maxItems: number;
};

/** A distinct line colour per slot (stable by selection order). */
const COLORS = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed'];

const WINDOWS = [
    { key: '3m', label: '3M', days: 90 },
    { key: '1y', label: '1Y', days: 400 },
] as const;

type WindowKey = (typeof WINDOWS)[number]['key'] | 'max';

type SearchResult = {
    id: number;
    name: string;
    set: string | null;
    image: string | null;
};

export default function Compare({ items, maxItems }: Props) {
    const [win, setWin] = useState<WindowKey>('1y');
    const [mode, setMode] = useState<CompareMode>('value');

    const ids = items.map((i) => i.id);
    const full = items.length >= maxItems;
    // A "Max" (multi-year, PriceCharting) view is offered when any card has one.
    const hasLongTerm = items.some((i) => i.series_long.length >= 2);

    // Selection lives in the URL (?ids=). Adding/removing re-renders with the
    // server-computed series, so a comparison is always shareable + reproducible.
    const setIds = (next: number[]) =>
        router.get(
            '/compare',
            { ids: next.join(',') },
            { preserveScroll: true, preserveState: true },
        );

    const addItem = (id: number) => {
        if (ids.includes(id) || full) {
            return;
        }

        setIds([...ids, id]);
    };

    const removeItem = (id: number) => setIds(ids.filter((x) => x !== id));

    // Window each series back from the most-recent point across ALL cards, so the
    // lines share an x-range. Colours are assigned by the card's slot.
    const { series, latest } = useMemo(() => {
        // "Max" plots each card's long-term monthly (PriceCharting) series as-is.
        if (win === 'max') {
            const series: CompareSeries[] = items.map((item, i) => ({
                id: item.id,
                name: item.name,
                color: COLORS[i % COLORS.length],
                points: item.series_long,
            }));
            const maxT = items
                .flatMap((i) => i.series_long.map((p) => p.t))
                .reduce((m, t) => Math.max(m, new Date(t).getTime()), 0);

            return { series, latest: maxT };
        }

        const allT = items.flatMap((i) => i.series.points.map((p) => p.t));
        const maxT = allT.reduce(
            (m, t) => Math.max(m, new Date(t).getTime()),
            0,
        );
        const days = WINDOWS.find((w) => w.key === win)?.days ?? 400;
        const cutoff = maxT - days * 86_400_000;

        const series: CompareSeries[] = items.map((item, i) => {
            const pts = item.series.points.filter(
                (p) => new Date(p.t).getTime() >= cutoff,
            );

            return {
                id: item.id,
                name: item.name,
                color: COLORS[i % COLORS.length],
                // Keep the full series if the window would leave <2 points.
                points: pts.length >= 2 ? pts : item.series.points,
            };
        });

        return { series, latest: maxT };
    }, [items, win]);

    // Per-card % change over the shown window (first → last visible point).
    const changePct = (id: number): number | null => {
        const pts = series.find((s) => s.id === id)?.points ?? [];

        if (pts.length < 2 || pts[0].price === 0) {
            return null;
        }

        return (pts[pts.length - 1].price / pts[0].price - 1) * 100;
    };

    return (
        <>
            <Head title="Compare card values" />

            <div className="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        Compare cards
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Track and contrast the value of up to {maxItems} cards
                        over time. Share the link to send a comparison.
                    </p>
                </div>

                <AddCard
                    disabled={full}
                    excludeIds={ids}
                    onAdd={addItem}
                    full={full}
                    maxItems={maxItems}
                />

                {items.length === 0 ? (
                    <div className="mt-6 rounded-xl border border-dashed border-border py-20 text-center">
                        <p className="text-sm text-muted-foreground">
                            Search above to add cards and compare their value
                            over time.
                        </p>
                    </div>
                ) : (
                    <>
                        {/* Legend + controls */}
                        <div className="mt-6 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <ul className="flex flex-wrap gap-2">
                                {items.map((item, i) => {
                                    const pct = changePct(item.id);

                                    return (
                                        <li
                                            key={item.id}
                                            className="flex items-center gap-2 rounded-lg border border-border bg-card py-1.5 pr-1.5 pl-2.5"
                                        >
                                            <span
                                                className="inline-block size-2.5 shrink-0 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        COLORS[i % COLORS.length],
                                                }}
                                            />
                                            <div className="min-w-0">
                                                <p className="max-w-[12rem] truncate text-sm font-medium">
                                                    {item.url ? (
                                                        <Link
                                                            href={item.url}
                                                            className="hover:underline"
                                                        >
                                                            {item.name}
                                                        </Link>
                                                    ) : (
                                                        item.name
                                                    )}
                                                </p>
                                                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    {item.latest != null
                                                        ? formatMoney(item.latest)
                                                        : 'No value'}
                                                    {pct != null && (
                                                        <span
                                                            className={cn(
                                                                'inline-flex items-center gap-0.5 font-medium',
                                                                pct >= 0
                                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                                    : 'text-red-600 dark:text-red-400',
                                                            )}
                                                        >
                                                            {pct >= 0 ? (
                                                                <TrendingUp className="size-3" />
                                                            ) : (
                                                                <TrendingDown className="size-3" />
                                                            )}
                                                            {pct >= 0 ? '+' : ''}
                                                            {pct.toFixed(0)}%
                                                        </span>
                                                    )}
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    removeItem(item.id)
                                                }
                                                aria-label={`Remove ${item.name}`}
                                                className="ml-1 rounded-full p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                                            >
                                                <X className="size-3.5" />
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>

                            <div className="flex shrink-0 items-center gap-2">
                                {/* Value vs % change */}
                                <div className="inline-flex items-center gap-0.5 rounded-md border border-border p-0.5">
                                    {(
                                        [
                                            ['value', 'Value'],
                                            ['index', '% change'],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => setMode(key)}
                                            className={cn(
                                                'rounded px-2 py-0.5 text-[11px] font-medium transition-colors',
                                                mode === key
                                                    ? 'bg-accent text-accent-foreground'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                                {/* Window */}
                                <div className="inline-flex items-center gap-0.5 rounded-md border border-border p-0.5">
                                    {[
                                        ...WINDOWS.map((w) => ({
                                            key: w.key as WindowKey,
                                            label: w.label,
                                        })),
                                        ...(hasLongTerm
                                            ? [
                                                  {
                                                      key: 'max' as WindowKey,
                                                      label: 'Max',
                                                  },
                                              ]
                                            : []),
                                    ].map((w) => (
                                        <button
                                            key={w.key}
                                            type="button"
                                            onClick={() => setWin(w.key)}
                                            className={cn(
                                                'rounded px-2 py-0.5 text-[11px] font-medium transition-colors',
                                                win === w.key
                                                    ? 'bg-accent text-accent-foreground'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {w.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>

                        <div className="mt-4 rounded-xl border border-border bg-card p-4">
                            <CompareChart
                                key={latest}
                                series={series}
                                mode={mode}
                                height={360}
                            />
                            <p className="mt-2 text-[11px] text-muted-foreground">
                                {win === 'max'
                                    ? 'Monthly price history, via PriceCharting. '
                                    : 'Weekly median of sold prices. '}
                                {mode === 'index'
                                    ? 'Each card is rebased to its own starting value so different price levels are comparable.'
                                    : 'Absolute market value in USD.'}
                            </p>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

/** Typeahead that searches the catalog and adds a card to the comparison. */
function AddCard({
    disabled,
    excludeIds,
    onAdd,
    full,
    maxItems,
}: {
    disabled: boolean;
    excludeIds: number[];
    onAdd: (id: number) => void;
    full: boolean;
    maxItems: number;
}) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const boxRef = useRef<HTMLDivElement>(null);

    // Debounced type-ahead via the lightweight /search/suggest endpoint (three
    // small LIKE queries) — NOT /api/v1/catalog, which also recomputes the whole
    // browse facet set (every card's attributes + grade/grader subqueries) per
    // request. All state updates happen inside the deferred callbacks; stale
    // results are hidden (dropdown gated on length), not cleared here.
    useEffect(() => {
        const term = q.trim();

        if (term.length < 2) {
            return;
        }

        const ctrl = new AbortController();
        const timer = setTimeout(() => {
            setLoading(true);
            fetch(`/search/suggest?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: ctrl.signal,
            })
                .then((r) => r.json())
                .then((body) => {
                    type Card = {
                        id: number;
                        name: string;
                        set: string | null;
                        thumb: string | null;
                    };
                    setResults(
                        (body.cards ?? []).map((c: Card) => ({
                            id: c.id,
                            name: c.name,
                            set: c.set,
                            image: c.thumb,
                        })),
                    );
                    setLoading(false);
                })
                .catch(() => setLoading(false));
        }, 250);

        return () => {
            ctrl.abort();
            clearTimeout(timer);
        };
    }, [q]);

    // Close the results on an outside click.
    useEffect(() => {
        const onClick = (e: MouseEvent) => {
            if (boxRef.current && !boxRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onClick);

        return () => document.removeEventListener('mousedown', onClick);
    }, []);

    const visible = results.filter((r) => !excludeIds.includes(r.id));

    return (
        <div ref={boxRef} className="relative max-w-xl">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                value={q}
                onChange={(e) => {
                    setQ(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                disabled={disabled}
                placeholder={
                    full
                        ? `Remove a card to add another (max ${maxItems})`
                        : 'Search cards to add…'
                }
                className="pl-9"
                aria-label="Search cards to compare"
            />

            {open && q.trim().length >= 2 && (
                <div className="absolute z-20 mt-1 max-h-80 w-full overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-md">
                    {loading && visible.length === 0 ? (
                        <div className="flex items-center gap-2 px-3 py-6 text-sm text-muted-foreground">
                            <Spinner className="size-4" /> Searching…
                        </div>
                    ) : visible.length === 0 ? (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No matching cards.
                        </p>
                    ) : (
                        visible.map((r) => (
                            <button
                                key={r.id}
                                type="button"
                                onClick={() => {
                                    onAdd(r.id);
                                    setQ('');
                                    setOpen(false);
                                }}
                                className="flex w-full items-center gap-3 rounded-md px-2 py-2 text-left text-sm transition-colors hover:bg-accent"
                            >
                                <span className="flex h-12 w-9 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                                    {r.image ? (
                                        <img
                                            src={r.image}
                                            alt=""
                                            className="size-full object-contain"
                                        />
                                    ) : null}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">
                                        {r.name}
                                    </span>
                                    {r.set && (
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {r.set}
                                        </span>
                                    )}
                                </span>
                                <Plus className="size-4 shrink-0 text-muted-foreground" />
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
