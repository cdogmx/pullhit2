import { router } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * Heart toggle that adds/removes a card from the user's wishlist. Optimistic —
 * flips immediately and reverts on error.
 */
export function WishlistButton({
    catalogItemId,
    wishlisted: initial,
    className,
}: {
    catalogItemId: number;
    wishlisted: boolean;
    className?: string;
}) {
    const [wishlisted, setWishlisted] = useState(initial);

    const toggle = (e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();

        const next = !wishlisted;
        setWishlisted(next); // optimistic

        const opts = {
            preserveScroll: true,
            preserveState: true,
            onError: () => setWishlisted(!next),
        };

        if (next) {
            router.post('/wishlist', { catalog_item_id: catalogItemId }, opts);
        } else {
            router.delete(`/wishlist/${catalogItemId}`, opts);
        }
    };

    return (
        <button
            type="button"
            onClick={toggle}
            aria-label={wishlisted ? 'Remove from wishlist' : 'Add to wishlist'}
            title={wishlisted ? 'On your wishlist' : 'Add to wishlist'}
            className={cn(
                'flex size-8 items-center justify-center rounded-full bg-background/85 ring-1 ring-border backdrop-blur transition-colors hover:bg-rose-500 hover:text-white hover:ring-rose-500',
                wishlisted ? 'text-rose-500' : 'text-foreground',
                className,
            )}
        >
            <Heart className={cn('size-4', wishlisted && 'fill-current')} />
        </button>
    );
}
