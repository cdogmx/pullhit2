import { router } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ListTargetPicker } from '@/components/shared/list-target-picker';
import type { TargetsResponse } from '@/components/shared/list-target-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    clearWishlistTargets,
    loadWishlistTargets,
} from '@/components/wishlist/wishlist-targets';
import { cn } from '@/lib/utils';

/**
 * Heart toggle for the wishlist. Removing is instant. Adding quick-adds to the
 * default wishlist when that's the only option; when the user has several
 * wishlists (or their tier allows another), it opens a picker to choose or
 * create one. Optimistic — flips immediately and reverts on error.
 */
export function WishlistButton({
    catalogItemId,
    wishlisted: initial,
    className,
    variant = 'icon',
}: {
    catalogItemId: number;
    wishlisted: boolean;
    className?: string;
    /** 'icon' = round heart overlay (browse tiles); 'button' = labelled button. */
    variant?: 'icon' | 'button';
}) {
    const [wishlisted, setWishlisted] = useState(initial);
    const [picker, setPicker] = useState<TargetsResponse | null>(null);
    const [target, setTarget] = useState<{
        id: number | null;
        newName: string | null;
    }>({ id: null, newName: null });
    const [busy, setBusy] = useState(false);

    const quickAdd = () => {
        setWishlisted(true);
        router.post(
            '/wishlist',
            { catalog_item_id: catalogItemId },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => setWishlisted(false),
            },
        );
    };

    const toggle = async (e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();

        if (wishlisted) {
            // Heart means "on any wishlist" — clear every list that has this card.
            setWishlisted(false);
            router.delete(`/wishlist/${catalogItemId}`, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => toast.success('Removed from wishlist.'),
                onError: () => setWishlisted(true),
            });

            return;
        }

        const t = await loadWishlistTargets();

        if (t.targets.length > 1 || t.can_create) {
            setPicker(t);
        } else {
            quickAdd();
        }
    };

    const submitPicker = (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        const creatingNew = Boolean(target.newName);

        router.post(
            '/wishlist',
            {
                catalog_item_id: catalogItemId,
                wishlist_id: target.id,
                new_wishlist_name: target.newName,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setWishlisted(true);
                    setPicker(null);

                    if (creatingNew) {
                        clearWishlistTargets(); // a new wishlist exists now
                    }

                    toast.success('Added to your wishlist.');
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <>
            {variant === 'button' ? (
                <Button
                    type="button"
                    size="sm"
                    variant={wishlisted ? 'secondary' : 'outline'}
                    onClick={toggle}
                    aria-label={
                        wishlisted ? 'Remove from wishlist' : 'Add to wishlist'
                    }
                    className={cn(wishlisted && 'text-rose-500', className)}
                >
                    <Heart
                        className={cn('size-4', wishlisted && 'fill-current')}
                    />
                    {wishlisted ? 'Wishlisted' : 'Wishlist'}
                </Button>
            ) : (
                <button
                    type="button"
                    onClick={toggle}
                    aria-label={
                        wishlisted ? 'Remove from wishlist' : 'Add to wishlist'
                    }
                    title={wishlisted ? 'On your wishlist' : 'Add to wishlist'}
                    className={cn(
                        'flex size-8 items-center justify-center rounded-full bg-background/85 ring-1 ring-border backdrop-blur transition-colors hover:bg-rose-500 hover:text-white hover:ring-rose-500',
                        wishlisted ? 'text-rose-500' : 'text-foreground',
                        className,
                    )}
                >
                    <Heart
                        className={cn('size-4', wishlisted && 'fill-current')}
                    />
                </button>
            )}

            <Dialog open={!!picker} onOpenChange={(o) => !o && setPicker(null)}>
                <DialogContent>
                    <form onSubmit={submitPicker}>
                        <DialogHeader>
                            <DialogTitle>Add to wishlist</DialogTitle>
                            <DialogDescription>
                                Choose which wishlist — or create a new one.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="py-4">
                            {picker && (
                                <ListTargetPicker
                                    endpoint="/wishlist/targets"
                                    label="wishlist"
                                    open
                                    preloaded={picker}
                                    onChange={(id, newName) =>
                                        setTarget({ id, newName })
                                    }
                                />
                            )}
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={busy}>
                                Add to wishlist
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
