import { Head, Link, router } from '@inertiajs/react';
import {
    Heart,
    LayoutGrid,
    List,
    Pencil,
    RectangleVertical,
    Tag,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ListTabs } from '@/components/shared/list-tabs';
import type { ListSummary } from '@/components/shared/list-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { EditWishlistItemDialog } from '@/components/wishlist/edit-wishlist-item-dialog';
import { cardHref, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { WishlistRow, WishlistSummary } from '@/types';

type Props = {
    wishlists: ListSummary[];
    activeWishlist: string;
    wishlistLimit: number | null;
    items: WishlistRow[];
    summary: WishlistSummary;
    publicUrl: string | null;
};

type ViewMode = 'list' | 'small' | 'large';

const VIEW_KEY = 'wishlist-view';
const VIEWS: ViewMode[] = ['list', 'small', 'large'];

function readView(): ViewMode {
    if (typeof window === 'undefined') {
        return 'list';
    }

    const raw = localStorage.getItem(VIEW_KEY);

    return VIEWS.includes(raw as ViewMode) ? (raw as ViewMode) : 'list';
}

export default function WishlistIndex({
    wishlists,
    activeWishlist,
    wishlistLimit,
    items,
    summary,
    publicUrl,
}: Props) {
    const active = wishlists.find((w) => w.slug === activeWishlist);
    const otherWishlists = wishlists.filter((w) => w.slug !== activeWishlist);
    const [editing, setEditing] = useState<WishlistRow | null>(null);
    const [confirmRemove, setConfirmRemove] = useState<WishlistRow | null>(
        null,
    );
    const [busy, setBusy] = useState(false);
    const [view, setView] = useState<ViewMode>('list');

    useEffect(() => {
        setView(readView());
    }, []);

    const changeView = (next: string) => {
        if (!VIEWS.includes(next as ViewMode)) {
            return;
        }

        const mode = next as ViewMode;
        setView(mode);
        localStorage.setItem(VIEW_KEY, mode);
    };

    const remove = (row: WishlistRow) => {
        const cardId = row.catalog_item?.id;
        if (!cardId || !active) {
            return;
        }

        setBusy(true);
        router.delete(`/wishlist/${cardId}`, {
            data: { wishlist_id: active.id },
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Removed from wishlist.');
                setConfirmRemove(null);
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <>
            <Head title="Wishlist" />

            <div className="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                    <div>
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
                                Public ·{' '}
                                {publicUrl.replace(/^https?:\/\//, '')}
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
                                        {summary.below_target} at or below
                                        target
                                    </span>
                                </>
                            )}
                        </p>
                    </div>

                    {items.length > 0 && (
                        <ViewToggle value={view} onChange={changeView} />
                    )}
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
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            className="mt-4"
                        >
                            <Link href="/browse">Browse cards</Link>
                        </Button>
                    </div>
                ) : view === 'list' ? (
                    <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                        {items.map((row) => (
                            <WishListRow
                                key={row.id}
                                row={row}
                                otherWishlists={otherWishlists}
                                onEdit={() => setEditing(row)}
                                onRemove={() => setConfirmRemove(row)}
                            />
                        ))}
                    </div>
                ) : (
                    <div
                        className={cn(
                            'grid gap-3',
                            view === 'small'
                                ? 'grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6'
                                : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4',
                        )}
                    >
                        {items.map((row) => (
                            <WishCard
                                key={row.id}
                                row={row}
                                size={view}
                                otherWishlists={otherWishlists}
                                onEdit={() => setEditing(row)}
                                onRemove={() => setConfirmRemove(row)}
                            />
                        ))}
                    </div>
                )}
            </div>

            <EditWishlistItemDialog
                item={
                    editing
                        ? {
                              id: editing.id,
                              name:
                                  editing.catalog_item?.display_name ??
                                  editing.catalog_item?.name ??
                                  'Wishlist item',
                              target_price: editing.target_price,
                              notes: editing.notes,
                          }
                        : null
                }
                wishlists={otherWishlists.map((w) => ({
                    id: w.id,
                    name: w.name,
                }))}
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
            />

            <ConfirmDialog
                open={confirmRemove !== null}
                onOpenChange={(o) => !o && setConfirmRemove(null)}
                title="Remove from wishlist?"
                description={
                    confirmRemove
                        ? `"${confirmRemove.catalog_item?.display_name ?? confirmRemove.catalog_item?.name ?? 'This card'}" will be removed from ${active?.name ?? 'this wishlist'}.`
                        : undefined
                }
                confirmLabel="Remove"
                destructive
                busy={busy}
                onConfirm={() => confirmRemove && remove(confirmRemove)}
            />
        </>
    );
}

function ViewToggle({
    value,
    onChange,
}: {
    value: ViewMode;
    onChange: (next: string) => void;
}) {
    return (
        <ToggleGroup
            type="single"
            value={value}
            onValueChange={(v) => v && onChange(v)}
            variant="outline"
            size="sm"
            className="justify-start"
            aria-label="Wishlist layout"
        >
            <ToggleGroupItem value="list" aria-label="List view" title="List">
                <List className="size-4" />
            </ToggleGroupItem>
            <ToggleGroupItem
                value="small"
                aria-label="Small card view"
                title="Small cards"
            >
                <LayoutGrid className="size-4" />
            </ToggleGroupItem>
            <ToggleGroupItem
                value="large"
                aria-label="Large card view"
                title="Large cards"
            >
                <RectangleVertical className="size-4" />
            </ToggleGroupItem>
        </ToggleGroup>
    );
}

function WishListRow({
    row,
    otherWishlists,
    onEdit,
    onRemove,
}: {
    row: WishlistRow;
    otherWishlists: ListSummary[];
    onEdit: () => void;
    onRemove: () => void;
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

    return (
        <div className="flex items-center gap-3 bg-card p-3 hover:bg-accent/40">
            <Link
                href={card ? cardHref(card) : '#'}
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
                    onKeyDown={(e) =>
                        e.key === 'Enter' && e.currentTarget.blur()
                    }
                    inputMode="decimal"
                    placeholder="Target $"
                    className="h-8 w-24"
                    aria-label="Target price"
                />
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    onClick={onEdit}
                    aria-label="Edit wishlist item"
                    title="Edit"
                >
                    <Pencil className="size-4" />
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    onClick={onRemove}
                    aria-label="Remove from wishlist"
                >
                    <X className="size-4" />
                </Button>
            </div>
        </div>
    );
}

function WishCard({
    row,
    size,
    otherWishlists,
    onEdit,
    onRemove,
}: {
    row: WishlistRow;
    size: 'small' | 'large';
    otherWishlists: ListSummary[];
    onEdit: () => void;
    onRemove: () => void;
}) {
    const card = row.catalog_item;
    const name = card?.display_name ?? card?.name ?? 'Unknown card';
    const compact = size === 'small';

    return (
        <div className="group relative overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-ring">
            <div className="absolute top-1.5 right-1.5 z-10 flex gap-1 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                <Button
                    size="icon"
                    variant="secondary"
                    className={cn(
                        'border border-border bg-background/90 shadow-sm',
                        compact ? 'size-7' : 'size-8',
                    )}
                    onClick={onEdit}
                    aria-label="Edit wishlist item"
                    title="Edit"
                >
                    <Pencil className={compact ? 'size-3.5' : 'size-4'} />
                </Button>
                <Button
                    size="icon"
                    variant="secondary"
                    className={cn(
                        'border border-border bg-background/90 text-muted-foreground shadow-sm hover:text-red-600',
                        compact ? 'size-7' : 'size-8',
                    )}
                    onClick={onRemove}
                    aria-label="Remove from wishlist"
                    title="Remove"
                >
                    <X className={compact ? 'size-3.5' : 'size-4'} />
                </Button>
            </div>

            <Link href={card ? cardHref(card) : '#'} className="block">
                <div className="aspect-[3/4] overflow-hidden bg-muted">
                    {card?.image_url ? (
                        <img
                            src={card.image_url}
                            alt={name}
                            loading="lazy"
                            className="size-full object-contain transition-transform group-hover:scale-105"
                        />
                    ) : (
                        <div className="flex size-full items-center justify-center text-xs text-muted-foreground">
                            No image
                        </div>
                    )}
                </div>
                <div
                    className={cn(
                        compact ? 'space-y-0.5 p-2' : 'space-y-1 p-3',
                    )}
                >
                    <p
                        className={cn(
                            'truncate font-medium',
                            compact ? 'text-xs' : 'text-sm',
                        )}
                        title={name}
                    >
                        {name}
                    </p>
                    <p
                        className={cn(
                            'truncate text-muted-foreground',
                            compact ? 'text-[10px]' : 'text-xs',
                        )}
                    >
                        {card?.set?.name}
                        {card?.number ? ` · ${card.number}` : ''}
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5">
                        {row.current_value != null ? (
                            <span
                                className={cn(
                                    'font-semibold',
                                    compact ? 'text-xs' : 'text-sm',
                                )}
                            >
                                {formatMoney(row.current_value, row.currency)}
                            </span>
                        ) : (
                            <span
                                className={cn(
                                    'text-muted-foreground',
                                    compact ? 'text-[10px]' : 'text-xs',
                                )}
                            >
                                No value
                            </span>
                        )}
                        {row.below_target && (
                            <Badge
                                className={cn(
                                    'bg-emerald-600 hover:bg-emerald-600',
                                    compact ? 'text-[9px]' : 'text-[10px]',
                                )}
                            >
                                At target
                            </Badge>
                        )}
                    </div>
                    {!compact && row.target_price != null && (
                        <p className="text-xs text-muted-foreground">
                            Target {formatMoney(row.target_price, row.currency)}
                        </p>
                    )}
                </div>
            </Link>

            {!compact && otherWishlists.length > 0 && (
                <div className="border-t border-border px-3 pb-3">
                    <select
                        aria-label="Move to wishlist"
                        title="Move to wishlist"
                        value=""
                        onChange={(e) => {
                            if (e.target.value) {
                                router.patch(
                                    `/wishlist/${row.id}`,
                                    { wishlist_id: Number(e.target.value) },
                                    { preserveScroll: true },
                                );
                            }
                        }}
                        className="mt-2 h-7 w-full rounded border border-border bg-background px-1 text-xs text-muted-foreground"
                    >
                        <option value="">Move…</option>
                        {otherWishlists.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
        </div>
    );
}

WishlistIndex.layout = {
    breadcrumbs: [{ title: 'Wishlist', href: '/wishlist' }],
};
