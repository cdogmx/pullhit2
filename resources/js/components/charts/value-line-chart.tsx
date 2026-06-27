import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatMoney } from '@/lib/format';
import type { PricePoint } from '@/types';

/** Compact date label, e.g. "Mar 22". */
function shortDate(iso: string): string {
    const d = new Date(iso);

    return Number.isNaN(d.getTime())
        ? iso
        : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

// Recharts injects `active`/`payload` into the content element at runtime; its
// own generic types vary across versions, so we type locally.
type TooltipDatum = { payload: { t: string; value: number; n?: number } };

function ChartTooltip({
    active,
    payload,
}: {
    active?: boolean;
    payload?: TooltipDatum[];
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const point = payload[0].payload;

    return (
        <div className="rounded-md border border-border bg-popover px-2.5 py-1.5 text-xs shadow-md">
            <p className="font-medium">{formatMoney(point.value)}</p>
            <p className="text-muted-foreground">
                {shortDate(point.t)}
                {point.n ? ` · ${point.n} sold` : ''}
            </p>
        </div>
    );
}

/**
 * A themed line chart with dots for a value-over-time series ({t, price} cents).
 * Trend-coloured (green up / red down). Renders nothing below 2 points.
 */
export function ValueLineChart({
    points,
    height = 200,
    className,
}: {
    points: PricePoint[];
    height?: number;
    className?: string;
}) {
    if (points.length < 2) {
        return null;
    }

    const data = points.map((p) => ({ t: p.t, value: p.price, n: p.n }));
    const up = points[points.length - 1].price >= points[0].price;
    const color = up ? '#10b981' : '#ef4444';

    return (
        <div className={className} style={{ height }}>
            <ResponsiveContainer width="100%" height="100%">
                <LineChart
                    data={data}
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
                        tickFormatter={(v: number) => formatMoney(v)}
                        tick={{ fontSize: 11, fill: 'currentColor' }}
                        tickLine={false}
                        axisLine={false}
                        width={56}
                        domain={['auto', 'auto']}
                    />
                    <Tooltip
                        content={<ChartTooltip />}
                        cursor={{ stroke: color, strokeOpacity: 0.3 }}
                    />
                    <Line
                        type="monotone"
                        dataKey="value"
                        stroke={color}
                        strokeWidth={2}
                        dot={{ r: 2.5, fill: color, strokeWidth: 0 }}
                        activeDot={{ r: 4 }}
                        isAnimationActive={false}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
