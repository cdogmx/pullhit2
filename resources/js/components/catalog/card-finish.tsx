import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { CatalogItem } from '@/types';

const FINISH_CLASS: Record<string, string> = {
    holo: 'finish-holo',
    reverse_holo: 'finish-reverse-holo',
    foil: 'finish-foil',
};

const FINISH_LABEL: Record<string, string> = {
    holo: 'Holo',
    reverse_holo: 'Reverse holo',
    foil: 'Foil',
};

/**
 * The printing's finish, preferring the raw attribute over the flattened
 * column so a partially-loaded resource still resolves.
 */
export function finishOf(
    item: Pick<CatalogItem, 'variant'> & {
        attributes?: Record<string, string | number | null>;
    },
): string | null {
    const raw = item.attributes?.variant ?? item.variant;

    return raw == null ? null : String(raw);
}

/**
 * Wraps a card image in a finish overlay (holo sheen, reverse-holo frame,
 * foil).
 *
 * Upstream catalogs publish one scan per product and treat the finishes as
 * price sub-types of it, so every printing of a card shares that one image —
 * there is no per-finish photo to fetch. The overlay is what distinguishes
 * Normal from Holo from Reverse Holo when they sit side by side. It is a
 * rendered treatment rather than a photograph of the foil, and the tooltip
 * says so; the CSS lives in resources/css/app.css.
 *
 * The overlay sizes itself to the rect `object-contain` paints — a card's 5:7
 * inside the wrapper's box — not to the wrapper. Drawn on the wrapper it
 * striped the empty slivers left down each side of the art. Override
 * `--finish-ar` for a child whose aspect is not a standard card.
 */
export function CardFinish({
    variant,
    aspect,
    className,
    children,
}: {
    variant: string | null | undefined;
    /** CSS aspect-ratio of the wrapped art; defaults to a card's 5/7. */
    aspect?: string;
    className?: string;
    children: ReactNode;
}) {
    const key = variant ?? '';
    const finish = FINISH_CLASS[key];

    return (
        <span
            className={cn('relative block', finish, className)}
            style={
                finish && aspect
                    ? ({ '--finish-ar': aspect } as CSSProperties)
                    : undefined
            }
            title={
                finish
                    ? `${FINISH_LABEL[key]} printing. Card catalogs publish one scan per card, so the finish is rendered here rather than photographed.`
                    : undefined
            }
        >
            {children}
        </span>
    );
}
