import { Head } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import { EditWishlistItemDialog } from '@/components/wishlist/edit-wishlist-item-dialog';
import { cardHref, formatMoney } from '@/lib/format';

type Item = {
    catalog_item_id: number | null;
    url?: string | null;
    name: string | null;
    number: string | null;
    image_url: string | null;
    set: string | null;
    current_value: number | null;
    currency: string;
    // Owner-only edit fields (present when canEdit).
    id?: number;
    target_price?: number | null;
    notes?: string | null;
};

type Props = {
    owner: { username: string };
    wishlist: { name: string; slug: string; is_default: boolean };
    summary: { item_count: number; total_value: number; currency: string };
    items: Item[];
    canEdit?: boolean;
    wishlists?: { id: number; name: string; slug: string }[];
};

/**
 * Public, read-only view of a user's wishlist — the cards they want + current
 * market value. The owner viewing their own page can edit items in place.
 */
export default function PublicWishlist({
    owner,
    wishlist,
    summary,
    items,
    canEdit = false,
    wishlists = [],
}: Props) {
    const title = wishlist.is_default
        ? `${owner.username}'s wishlist`
        : `${owner.username} · ${wishlist.name}`;

    const [editing, setEditing] = useState<Item | null>(null);

    return (
        <>
            <Head title={title} />

            <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        {title}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {summary.item_count.toLocaleString()} cards ·{' '}
                        <span className="font-medium text-foreground">
                            {formatMoney(summary.total_value, summary.currency)}
                        </span>{' '}
                        to complete
                    </p>
                </div>

                {items.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border py-20 text-center text-sm text-muted-foreground">
                        This wishlist is empty.
                    </div>
                ) : (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {items.map((h, i) => (
                            <div key={i} className="relative">
                                {canEdit && h.id != null && (
                                    <button
                                        type="button"
                                        onClick={() => setEditing(h)}
                                        className="absolute top-2 right-2 z-10 flex size-7 items-center justify-center rounded-md border border-border bg-background/90 text-muted-foreground shadow-sm hover:text-foreground"
                                        aria-label="Edit wishlist item"
                                        title="Edit wishlist item"
                                    >
                                        <Pencil className="size-3.5" />
                                    </button>
                                )}
                                <a
                                    href={cardHref(h)}
                                    className="group block overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-ring"
                                >
                                    <div className="aspect-[3/4] overflow-hidden bg-muted">
                                        {h.image_url ? (
                                            <img
                                                src={h.image_url}
                                                alt={h.name ?? ''}
                                                loading="lazy"
                                                className="size-full object-contain transition-transform group-hover:scale-105"
                                            />
                                        ) : (
                                            <div className="flex size-full items-center justify-center text-xs text-muted-foreground">
                                                No image
                                            </div>
                                        )}
                                    </div>
                                    <div className="space-y-1 p-3">
                                        <p
                                            className="truncate text-sm font-medium"
                                            title={h.name ?? ''}
                                        >
                                            {h.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {h.set}
                                            {h.number ? ` · ${h.number}` : ''}
                                        </p>
                                        {h.current_value != null && (
                                            <p className="text-sm font-semibold">
                                                {formatMoney(
                                                    h.current_value,
                                                    h.currency,
                                                )}
                                            </p>
                                        )}
                                    </div>
                                </a>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <EditWishlistItemDialog
                item={
                    editing && editing.id != null
                        ? {
                              id: editing.id,
                              name: editing.name ?? 'Wishlist item',
                              target_price: editing.target_price ?? null,
                              notes: editing.notes ?? null,
                          }
                        : null
                }
                wishlists={wishlists
                    .filter((w) => w.slug !== wishlist.slug)
                    .map((w) => ({ id: w.id, name: w.name }))}
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
            />
        </>
    );
}
