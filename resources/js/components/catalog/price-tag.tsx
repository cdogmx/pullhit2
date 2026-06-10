import { Badge } from '@/components/ui/badge';
import {
    confidenceVariant,
    formatMoney,
    formatTrend,
    relativeTime,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import type { MarketValue } from '@/types';

/**
 * Renders a market value as a distribution — never a bare number (§7). `compact`
 * for list tiles (median + confidence), `full` for the detail page
 * (median · range · n sales · as of · confidence · trend).
 */
export function PriceTag({
    value,
    variant = 'compact',
    className,
}: {
    value: MarketValue;
    variant?: 'compact' | 'full';
    className?: string;
}) {
    const { currency } = value;

    if (variant === 'compact') {
        const trend = formatTrend(value.trend_30d);
        const up = (value.trend_30d ?? 0) > 0;

        return (
            <div className={cn('flex items-center gap-1.5', className)}>
                <span className="text-sm font-semibold">
                    {formatMoney(value.median, currency)}
                </span>
                {trend && (
                    <span
                        className={cn(
                            'text-xs font-medium',
                            up
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-red-600 dark:text-red-400',
                        )}
                        title="30-day change"
                    >
                        {trend}
                    </span>
                )}
                <span
                    className="size-1.5 shrink-0 rounded-full bg-current opacity-60"
                    title={`${value.confidence_label} confidence · ${value.n_sales} sales${value.is_estimated ? ' · estimated' : ''}`}
                    aria-hidden
                />
            </div>
        );
    }

    const trend = formatTrend(value.trend_30d);

    return (
        <div className={cn('space-y-2', className)}>
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span className="text-2xl font-bold tracking-tight">
                    {formatMoney(value.median, currency)}
                </span>
                <span className="text-sm text-muted-foreground">
                    {formatMoney(value.low, currency)}–
                    {formatMoney(value.high, currency)}
                </span>
                {trend && (
                    <span className="text-sm font-medium text-muted-foreground">
                        {trend}
                    </span>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <Badge variant={confidenceVariant(value.confidence_label)}>
                    {value.confidence_label} confidence
                </Badge>
                {value.is_estimated && <Badge variant="outline">Estimated</Badge>}
                <span>
                    {value.n_sales} {value.is_estimated ? 'est. comps' : 'sales'}
                </span>
                {value.computed_at && (
                    <span>· {relativeTime(value.computed_at)}</span>
                )}
            </div>
        </div>
    );
}
