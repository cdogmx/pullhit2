import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { ChartWatermark } from '@/components/charts/chart-watermark';
import { formatMoney } from '@/lib/format';
import type { PricePoint } from '@/types';

export type CompareSeries = {
    id: number;
    name: string;
    color: string;
    points: PricePoint[];
};

/** Compact date label, e.g. "Mar 22". */
function shortDate(iso: string): string {
    const d = new Date(iso);

    return Number.isNaN(d.getTime())
        ? iso
        : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

const fmtValue = (v: number, mode: CompareMode): string =>
    mode === 'index'
        ? `${v > 0 ? '+' : ''}${v.toFixed(0)}%`
        : formatMoney(v);

export type CompareMode = 'value' | 'index';

type Row = { t: string } & Record<string, number | string>;

/**
 * Merge each series' weekly points into rows keyed by week, one column per
 * series (`s{id}`). In "index" mode each series is rebased to its own first
 * visible point (percentage change), so cards of very different price magnitudes
 * are comparable on one axis.
 */
function buildRows(series: CompareSeries[], mode: CompareMode): Row[] {
    const baseline: Record<number, number> = {};

    for (const s of series) {
        baseline[s.id] = s.points[0]?.price ?? 0;
    }

    const byWeek = new Map<string, Row>();

    for (const s of series) {
        for (const p of s.points) {
            const row = byWeek.get(p.t) ?? { t: p.t };
            const base = baseline[s.id];
            row[`s${s.id}`] =
                mode === 'index' && base > 0
                    ? (p.price / base - 1) * 100
                    : p.price;
            byWeek.set(p.t, row);
        }
    }

    return [...byWeek.values()].sort(
        (a, b) => new Date(a.t).getTime() - new Date(b.t).getTime(),
    );
}

type TooltipItem = { name: string; value: number; color: string };

function CompareTooltip({
    active,
    payload,
    label,
    mode,
}: {
    active?: boolean;
    payload?: TooltipItem[];
    label?: string;
    mode: CompareMode;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    return (
        <div className="rounded-md border border-border bg-popover px-2.5 py-1.5 text-xs shadow-md">
            <p className="mb-1 font-medium text-muted-foreground">
                {label ? shortDate(label) : ''}
            </p>
            <ul className="space-y-0.5">
                {payload.map((item, i) => (
                    <li key={i} className="flex items-center gap-2">
                        <span
                            className="inline-block size-2 shrink-0 rounded-full"
                            style={{ backgroundColor: item.color }}
                        />
                        <span className="flex-1 truncate">{item.name}</span>
                        <span className="font-medium">
                            {fmtValue(item.value, mode)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Multi-series value-over-time chart for the compare page: one coloured line per
 * card. Absolute value ($) or rebased percentage change, selectable by `mode`.
 */
export function CompareChart({
    series,
    mode = 'value',
    height = 320,
}: {
    series: CompareSeries[];
    mode?: CompareMode;
    height?: number;
}) {
    const rows = buildRows(series, mode);

    if (rows.length < 2) {
        return (
            <div
                className="flex items-center justify-center text-center text-sm text-muted-foreground"
                style={{ height }}
            >
                Not enough sold-price history to chart these cards yet.
            </div>
        );
    }

    return (
        <div className="relative" style={{ height }}>
            <ChartWatermark />
            <ResponsiveContainer
                width="100%"
                height="100%"
                className="relative z-10"
            >
                <LineChart
                    data={rows}
                    margin={{ top: 8, right: 12, bottom: 0, left: 4 }}
                    className="text-muted-foreground"
                >
                    <CartesianGrid
                        vertical={false}
                        strokeDasharray="3 3"
                        className="stroke-border"
                    />
                    <XAxis
                        dataKey="t"
                        tickFormatter={shortDate}
                        tick={{ fontSize: 11, fill: 'currentColor' }}
                        tickLine={false}
                        axisLine={false}
                        minTickGap={32}
                    />
                    <YAxis
                        tickFormatter={(v: number) => fmtValue(v, mode)}
                        tick={{ fontSize: 11, fill: 'currentColor' }}
                        tickLine={false}
                        axisLine={false}
                        width={56}
                        domain={['auto', 'auto']}
                    />
                    <Tooltip
                        content={<CompareTooltip mode={mode} />}
                        cursor={{ strokeOpacity: 0.3 }}
                    />
                    {series.map((s) => (
                        <Line
                            key={s.id}
                            type="monotone"
                            dataKey={`s${s.id}`}
                            name={s.name}
                            stroke={s.color}
                            strokeWidth={2}
                            dot={false}
                            activeDot={{ r: 4 }}
                            connectNulls
                            isAnimationActive={false}
                        />
                    ))}
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
