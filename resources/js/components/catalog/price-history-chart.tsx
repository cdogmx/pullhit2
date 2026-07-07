import { useEffect, useMemo, useState } from 'react';
import { ValueLineChart } from '@/components/charts/value-line-chart';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { PriceHistory, PricePoint } from '@/types';

const WINDOWS = [
    { key: '3m', label: '3M', days: 90 },
    { key: '1y', label: '1Y', days: 400 },
] as const;

type WindowKey = (typeof WINDOWS)[number]['key'] | 'max';

/** A selectable priced state (condition or graded slab). */
export type ChartState = { state_key: string; label: string };

/**
 * The card page's "Price history" chart — weekly-median sold prices over a
 * selectable window, drawn from the same observations the value is built on.
 * A condition/grade selector switches the series between priced states (raw
 * NM/LP/…, or specific graded slabs), fetching each on demand and caching it.
 * Honest about thin data: shows an "estimated" note when there aren't enough
 * real sales, and a clear message when a chosen state has no series yet.
 */
export function PriceHistoryChart({
    history,
    itemId,
    states = [],
    defaultStateKey,
    longTerm = [],
}: {
    /** Server-rendered series for the default state. */
    history: PriceHistory;
    itemId: number;
    /** The card's priced states, for the condition/grade selector. */
    states?: ChartState[];
    /** The state_key the server-rendered `history` belongs to. */
    defaultStateKey?: string;
    /** Long-term monthly series (PriceCharting) — powers the "Max" window. */
    longTerm?: PricePoint[];
}) {
    const hasLongTerm = longTerm.length >= 2;
    // Lead with the multi-year view when we have little/no sold history of our own.
    const [win, setWin] = useState<WindowKey>(
        hasLongTerm && history.points.length < 2 ? 'max' : '1y',
    );
    const initialKey = defaultStateKey ?? states[0]?.state_key ?? '';
    const [stateKey, setStateKey] = useState(initialKey);
    // Per-state series cache, seeded with the server-rendered default. A state
    // is "loading" until its entry lands here (success or failure both fill it).
    const [cache, setCache] = useState<Record<string, PriceHistory>>(
        initialKey ? { [initialKey]: history } : {},
    );
    const loading = !!stateKey && !cache[stateKey];

    // Fetch a state's series the first time it's selected; cache it after. On
    // failure we cache an empty series so it stops loading and shows the note.
    useEffect(() => {
        if (!stateKey || cache[stateKey]) {
            return;
        }

        let active = true;
        fetch(
            `/api/v1/catalog/${itemId}/price-history?state_key=${encodeURIComponent(stateKey)}`,
            { headers: { Accept: 'application/json' } },
        )
            .then((r) => r.json())
            .then((data: PriceHistory) => {
                if (active) {
                    setCache((c) => ({ ...c, [stateKey]: data }));
                }
            })
            .catch(() => {
                if (active) {
                    setCache((c) => ({
                        ...c,
                        [stateKey]: { points: [], estimated: true },
                    }));
                }
            });

        return () => {
            active = false;
        };
    }, [stateKey, itemId, cache]);

    const current = cache[stateKey] ?? history;

    const shown = useMemo(() => {
        // "Max" plots the long-term monthly (PriceCharting) series as-is.
        if (win === 'max') {
            return longTerm;
        }

        const pts = current.points;

        if (pts.length < 2) {
            return pts;
        }

        // Window is measured back from the most recent point (pure — no clock
        // read in render), so the chart is stable regardless of when it renders.
        const days = WINDOWS.find((w) => w.key === win)?.days ?? 400;
        const latest = new Date(pts[pts.length - 1].t).getTime();
        const cutoff = latest - days * 86_400_000;
        const filtered = pts.filter((p) => new Date(p.t).getTime() >= cutoff);

        // Don't collapse to an empty chart if the window has <2 points.
        return filtered.length >= 2 ? filtered : pts;
    }, [current.points, win, longTerm]);

    // Nothing to show at all (no sold series, no states, no long-term line).
    if (history.points.length < 2 && states.length <= 1 && !hasLongTerm) {
        return null;
    }

    const isMax = win === 'max';
    const hasSeries = isMax ? hasLongTerm : current.points.length >= 2;

    return (
        <div className="mt-4 rounded-lg border border-border/60 bg-card p-3">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Price history
                </p>
                <div className="flex items-center gap-2">
                    {states.length > 1 && (
                        <Select value={stateKey} onValueChange={setStateKey}>
                            <SelectTrigger className="h-7 w-auto gap-1 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {states.map((s) => (
                                    <SelectItem
                                        key={s.state_key}
                                        value={s.state_key}
                                        className="text-xs"
                                    >
                                        {s.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <div className="inline-flex items-center gap-0.5 rounded-md border border-border p-0.5">
                        {[
                            ...WINDOWS.map((w) => ({ key: w.key, label: w.label })),
                            ...(hasLongTerm
                                ? [{ key: 'max' as const, label: 'Max' }]
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

            {hasSeries ? (
                <>
                    <ValueLineChart points={shown} height={180} />
                    <p className="mt-1 text-[11px] text-muted-foreground">
                        {isMax
                            ? 'Monthly price, via PriceCharting'
                            : current.estimated
                              ? 'Estimated trend — limited real sold data'
                              : 'Weekly median of sold prices'}
                    </p>
                </>
            ) : (
                <div className="flex h-[180px] items-center justify-center text-center text-xs text-muted-foreground">
                    {loading
                        ? 'Loading…'
                        : 'Not enough sold data to chart this state yet.'}
                </div>
            )}
        </div>
    );
}
