import { useMemo, useState } from 'react';
import { ValueLineChart } from '@/components/charts/value-line-chart';
import { cn } from '@/lib/utils';
import type { PriceHistory } from '@/types';

const WINDOWS = [
    { key: '3m', label: '3M', days: 90 },
    { key: '1y', label: '1Y', days: 400 },
] as const;

/**
 * The card page's "Price history" chart — weekly-median sold prices over a
 * selectable window, drawn from the same observations the value is built on.
 * Honest about thin data: shows an "estimated" note when there aren't enough
 * real sales. Renders nothing below 2 points.
 */
export function PriceHistoryChart({ history }: { history: PriceHistory }) {
    const [win, setWin] = useState<(typeof WINDOWS)[number]['key']>('1y');

    const shown = useMemo(() => {
        const pts = history.points;

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
    }, [history.points, win]);

    if (history.points.length < 2) {
        return null;
    }

    return (
        <div className="mt-4 rounded-lg border border-border/60 bg-card p-3">
            <div className="mb-2 flex items-center justify-between">
                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Price history
                </p>
                <div className="inline-flex items-center gap-0.5 rounded-md border border-border p-0.5">
                    {WINDOWS.map((w) => (
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

            <ValueLineChart points={shown} height={180} />

            <p className="mt-1 text-[11px] text-muted-foreground">
                {history.estimated
                    ? 'Estimated trend — limited real sold data'
                    : 'Weekly median of sold prices'}
            </p>
        </div>
    );
}
