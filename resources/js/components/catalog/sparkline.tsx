import { cn } from '@/lib/utils';
import type { PricePoint } from '@/types';

/** Dependency-free SVG sparkline of a card's recent price trend. */
export function Sparkline({
    points,
    className,
}: {
    points: PricePoint[];
    className?: string;
}) {
    if (points.length < 2) {
        return null;
    }

    const w = 240;
    const h = 44;
    const pad = 2;
    const prices = points.map((p) => p.price);
    const min = Math.min(...prices);
    const max = Math.max(...prices);
    const range = max - min || 1;
    const stepX = (w - pad * 2) / (points.length - 1);

    const coords = points.map((p, i) => {
        const x = pad + i * stepX;
        const y = pad + (h - pad * 2) * (1 - (p.price - min) / range);

        return `${x.toFixed(1)},${y.toFixed(1)}`;
    });

    const up = prices[prices.length - 1] >= prices[0];

    return (
        <svg
            viewBox={`0 0 ${w} ${h}`}
            preserveAspectRatio="none"
            className={cn('h-11 w-full', className)}
            aria-hidden
        >
            <polyline
                points={coords.join(' ')}
                fill="none"
                strokeWidth={1.5}
                strokeLinejoin="round"
                strokeLinecap="round"
                className={up ? 'stroke-emerald-500' : 'stroke-red-500'}
            />
        </svg>
    );
}
