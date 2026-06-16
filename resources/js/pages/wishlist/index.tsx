import { Head, Link, router } from '@inertiajs/react';
import { Heart, Tag, X } from 'lucide-react';
import { useState } from 'react';
import { ListTabs, type ListSummary } from '@/components/shared/list-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/format';
import type { WishlistRow, WishlistSummary } from '@/types';

type Props = {
    wishlists: ListSummary[];
    activeWishlist: string;
    wishlistLimit: number | null;
    items: WishlistRow[];
    summary: WishlistSummary;
    publicUrl: string | null;
};

export default function WishlistIndex({
    wishlists,
    activeWishlist,
    wishlistLimit,
    items,
    summary,
    publicUrl,
}: Props) {
    const otherWishlists = wishlists.filter((w) => w.slug !== activeWishlist);
    return (
        <>
            <Head title="Wishlist" />

            <div className="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight">
                        Your wishlist
                    </h1>
                    {publicUrl ? (
                        <a
                            href={publicUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        >
                            Public · {publicUrl.replace(/^https?:\/\//, '')}
                        </a>
                    ) : (
                        <Link
                            href="/settings/profile"
                            className="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        >
                            Private · make it public
                        </Link>
                    )}
                    <p className="mt-1 text-sm text-muted-foreground">
                        {summary.item_count.toLocaleString()}{' '}
                        {summary.item_count === 1 ? 'card' : 'cards'}
                        {summary.below_target > 0 && (
                            <>
                                {' · '}
                                <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                    {summary.below_target} at or below target
                                </span>
                            </>
                        )}
                    </p>
                </div>

                <div className="mb-4">
                    <ListTabs
                        lists={wishlists}
                        active={activeWishlist}
                        limit={wishlistLimit}
                        basePath="/wishlist"
                        queryKey="wishlist"
                        entityBase="/wishlists"
                        noun="wishlist"
                    />
                </div>

                {items.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border py-20 text-center">
                        <Heart className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 text-sm text-muted-foreground">
                            This wishlist is empty. Tap the{' '}
                            <Heart className="inline size-3.5" /> on any card to
                            add it.
                        </p>
                        <Button asChild variant="outline" size="sm" className="mt-4">
                            <Link href="/browse">Browse cards</Link>
                        </Button>
                    </div>
                ) : (
                    <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                        {items.map((row) => (
                            <WishRow
                                key={row.id}
                                row={row}
                                otherWishlists={otherWishlists}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function WishRow({
    row,
    otherWishlists,
}: {
    row: WishlistRow;
    otherWishlists: ListSummary[];
}) {
    const card = row.catalog_item;
    const [target, setTarget] = useState(
        row.target_price != null ? String(row.target_price / 100) : '',
    );

    const saveTarget = () => {
        const dollars = target.trim();
        const cents =
            dollars === '' ? null : Math.round(parseFloat(dollars) * 100);
        if (cents === row.target_price) {
            return;
        }
        router.patch(
            `/wishlist/${row.id}`,
            { target_price: Number.isFinite(cents as number) ? cents : null },
            { preserveScroll: true, preserveState: true },
        );
    };

    const remove = () =>
        card &&
        router.delete(`/wishlist/${card.id}`, { preserveScroll: true });

    return (
        <div className="flex items-center gap-3 bg-card p-3 hover:bg-accent/40">
            <Link
                href={card ? `/catalog/${card.id}` : '#'}
                className="flex min-w-0 flex-1 items-center gap-3"
            >
                <div className="h-16 w-12 shrink-0 overflow-hidden rounded bg-muted">
                    {card?.image_url ? (
                        <img
                            src={card.image_url}
                            alt={card.display_name ?? card.name}
                            loading="lazy"
                            className="size-full object-contain"
                        />
                    ) : null}
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                        {card?.display_name ?? card?.name ?? 'Unknown card'}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {card?.set?.name}
                        {card?.number ? ` · ${card.number}` : ''}
                    </p>
                    <p className="mt-0.5 text-sm">
                        {row.current_value != null ? (
                            <span className="font-semibold">
                                {formatMoney(row.current_value, row.currency)}
                            </span>
                        ) : (
                            <span className="text-muted-foreground">
                                No market value yet
                            </span>
                        )}
                        {row.below_target && (
                            <Badge className="ml-2 bg-emerald-600 text-[10px] hover:bg-emerald-600">
                                At target
                            </Badge>
                        )}
                    </p>
                </div>
            </Link>

            <div className="flex shrink-0 items-center gap-1.5">
                {otherWishlists.length > 0 && (
                    <select
                        aria-label="Move to wishlist"
                        title="Move to wishlist"
                        value=""
                        onChange={(e) =>
                            e.target.value &&
                            router.patch(
                                `/wishlist/${row.id}`,
                                { wishlist_id: Number(e.target.value) },
                                { preserveScroll: true },
                            )
                        }
                        className="h-8 rounded border border-border bg-background px-1 text-xs text-muted-foreground"
                    >
                        <option value="">Move…</option>
                        {otherWishlists.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.name}
                            </option>
                        ))}
                    </select>
                )}
                <Tag className="size-3.5 text-muted-foreground" />
                <Input
                    value={target}
                    onChange={(e) => setTarget(e.target.value)}
                    onBlur={saveTarget}
                    onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
                    inputMode="decimal"
                    placeholder="Target $"
                    className="h-8 w-24"
                    aria-label="Target price"
                />
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    onClick={remove}
                    aria-label="Remove from wishlist"
                >
                    <X className="size-4" />
                </Button>
            </div>
        </div>
    );
}

WishlistIndex.layout = {
    breadcrumbs: [{ title: 'Wishlist', href: '/wishlist' }],
};
