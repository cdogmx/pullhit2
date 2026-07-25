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

/**
 * "Add to wishlist" for a multi-select batch. Mirrors the single heart: adds
 * straight to the default list when that's the only option, and otherwise opens
 * the same picker to choose or create one. Cards already on the target list are
 * left alone — the add is idempotent server-side.
 */
export function BulkWishlistButton({
    catalogItemIds,
    onAdded,
}: {
    catalogItemIds: number[];
    /** Fired after a successful add — e.g. to clear the selection. */
    onAdded?: () => void;
}) {
    const [picker, setPicker] = useState<TargetsResponse | null>(null);
    const [target, setTarget] = useState<{
        id: number | null;
        newName: string | null;
    }>({ id: null, newName: null });
    const [busy, setBusy] = useState(false);

    const n = catalogItemIds.length;
    const label = n === 1 ? 'Added 1 card' : `Added ${n} cards`;

    const post = (payload: Record<string, unknown>, creatingNew = false) => {
        setBusy(true);
        router.post(
            '/wishlist/bulk',
            { catalog_item_ids: catalogItemIds, ...payload },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setPicker(null);

                    if (creatingNew) {
                        clearWishlistTargets(); // a new wishlist exists now
                    }

                    onAdded?.();
                    toast.success(`${label} to your wishlist.`);
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const start = async () => {
        const t = await loadWishlistTargets();

        // Nothing to choose between — go straight to the default list.
        if (t.targets.length > 1 || t.can_create) {
            setPicker(t);
        } else {
            post({});
        }
    };

    return (
        <>
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="h-8"
                disabled={busy || n === 0}
                onClick={start}
            >
                <Heart className="size-4" />
                Wishlist
            </Button>

            <Dialog open={!!picker} onOpenChange={(o) => !o && setPicker(null)}>
                <DialogContent>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(
                                {
                                    wishlist_id: target.id,
                                    new_wishlist_name: target.newName,
                                },
                                Boolean(target.newName),
                            );
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>
                                Add {n} {n === 1 ? 'card' : 'cards'} to wishlist
                            </DialogTitle>
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
                                Add {n} {n === 1 ? 'card' : 'cards'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
