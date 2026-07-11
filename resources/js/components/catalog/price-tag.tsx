import { Flame } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    confidenceDotClass,
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
        // Low-confidence values recede ("~" + muted) so a thin-market price is
        // never mistaken for a trusted one when scanning a list.
        const low = value.confidence_label === 'Low';
        // Graded values carry their slab label ("PSA 10") — show it so a list
        // filtered to a grade reads e.g. "PSA 10 $500", not a bare number.
        const graded = value.grade != null;

        return (
            <div className={cn('flex items-center gap-1.5', className)}>
                {graded && (
                    <Badge
                        variant="secondary"
                        className="shrink-0 px-1.5 py-0 text-[10px] font-semibold"
                    >
                        {value.label}
                    </Badge>
                )}
                <span
                    className={cn(
                        'text-sm font-semibold',
                        low && 'font-medium text-muted-foreground',
                    )}
                >
                    {low ? '~' : ''}
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
                {value.is_surging && (
                    <Flame
                        className="size-3.5 shrink-0 text-orange-500"
                        aria-label="Surging"
                    />
                )}
                <span
                    className={cn(
                        'size-1.5 shrink-0 rounded-full bg-current',
                        confidenceDotClass(value.confidence_label),
                    )}
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
                {trend && (
                    <span className="text-sm font-medium text-muted-foreground">
                        {trend}
                    </span>
                )}
                {/* The spread of actual sold prices — labelled so it isn't read as
                    a confidence figure (confidence is the badge below). */}
                <span
                    className="text-xs text-muted-foreground"
                    title="Lowest to highest recent sold price. A wider spread means less certainty in the value."
                >
                    sold {formatMoney(value.low, currency)}–
                    {formatMoney(value.high, currency)}
                </span>
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <Badge
                    variant={confidenceVariant(value.confidence_label)}
                    className={cn(
                        value.confidence_label === 'Low' &&
                            'border-amber-500/40 text-amber-600 dark:text-amber-400',
                    )}
                >
                    {value.confidence_label} confidence
                </Badge>
                {value.is_estimated && <Badge variant="outline">Estimated</Badge>}
                {value.is_surging && (
                    <Badge
                        className="gap-1 border-transparent bg-orange-500 text-white hover:bg-orange-500"
                        title={`Sold prices are up ${value.trend_30d}% over 30 days on real sales.`}
                    >
                        <Flame className="size-3" />
                        Surging
                    </Badge>
                )}
                {value.top_seller_share != null && value.top_seller_share > 0.5 && (
                    <Badge
                        variant="outline"
                        className="border-amber-500/40 text-amber-600 dark:text-amber-400"
                        title={`One seller accounts for ${Math.round(value.top_seller_share * 100)}% of these comps — treat with caution.`}
                    >
                        Single seller
                    </Badge>
                )}
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
