import { Head } from '@inertiajs/react';
import { formatMoney } from '@/lib/format';

type Item = {
    catalog_item_id: number | null;
    name: string | null;
    number: string | null;
    image_url: string | null;
    set: string | null;
    current_value: number | null;
    currency: string;
};

type Props = {
    owner: { name: string; username: string | null };
    summary: { item_count: number; total_value: number; currency: string };
    items: Item[];
};

/**
 * Public, read-only view of a user's wishlist — the cards they want + current
 * market value. Never shows target prices or notes (those stay private).
 */
export default function PublicWishlist({ owner, summary, items }: Props) {
    return (
        <>
            <Head title={`${owner.name}'s wishlist`} />

            <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        {owner.name}&rsquo;s wishlist
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
                            <a
                                key={i}
                                href={`/catalog/${h.catalog_item_id}`}
                                className="group overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-ring"
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
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
