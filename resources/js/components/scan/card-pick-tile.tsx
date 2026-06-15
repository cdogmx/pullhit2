import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CatalogItem } from '@/types';

/**
 * A selectable card tile with a full-size portrait image — used for both the
 * scan's candidate matches and catalog-search results, so the picker is visual
 * instead of a cramped text dropdown.
 */
export function CardPickTile({
    card,
    selected = false,
    score,
    onSelect,
}: {
    card: CatalogItem;
    selected?: boolean;
    /** Match confidence 0–1 (scan candidates only). */
    score?: number;
    onSelect: () => void;
}) {
    const meta = [card.number, card.set?.name].filter(Boolean).join(' · ');
    const value = card.market_value
        ? formatMoney(card.market_value.median, card.market_value.currency)
        : null;

    return (
        <button
            type="button"
            onClick={onSelect}
            aria-pressed={selected}
            className={cn(
                'flex w-28 shrink-0 flex-col gap-1 rounded-lg border p-1.5 text-left transition-colors',
                selected
                    ? 'border-primary ring-2 ring-primary/40'
                    : 'border-border hover:bg-accent/40',
            )}
        >
            {card.image_url ? (
                <img
                    src={card.image_url}
                    alt=""
                    loading="lazy"
                    className="h-36 w-full rounded object-contain"
                />
            ) : (
                <div className="flex h-36 w-full items-center justify-center rounded bg-muted px-1 text-center text-[10px] text-muted-foreground">
                    No image
                </div>
            )}
            <span className="truncate text-xs font-medium leading-tight">
                {card.display_name ?? card.name}
            </span>
            {meta && (
                <span className="truncate text-[10px] leading-tight text-muted-foreground">
                    {meta}
                </span>
            )}
            {(value || score != null) && (
                <span className="truncate text-[10px] leading-tight text-muted-foreground">
                    {value}
                    {score != null
                        ? `${value ? ' · ' : ''}${Math.round(score * 100)}%`
                        : ''}
                </span>
            )}
        </button>
    );
}
