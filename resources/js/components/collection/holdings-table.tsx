import { Link, router } from '@inertiajs/react';
import {
    FolderInput,
    Pencil,
    Search,
    StickyNote,
    Tag,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { CollectionFolderPicker } from '@/components/collection/collection-folder-picker';
import type { CollectionFolderChoice } from '@/components/collection/collection-folder-picker';
import { EditHoldingDialog } from '@/components/collection/edit-holding-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cardHref, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { GradingCompanyOption, Holding } from '@/types';

/** A folder within a collection (shareable metadata + count). */
export type FolderRow = {
    id: number;
    name: string;
    slug: string;
    is_public: boolean;
    items_count: number;
    public_url: string | null;
};

const ALL = '__all__';

const SORTS = [
    { value: 'value_desc', label: 'Value: high to low' },
    { value: 'value_asc', label: 'Value: low to high' },
    { value: 'pl_desc', label: 'P&L: best first' },
    { value: 'pl_asc', label: 'P&L: worst first' },
    { value: 'name', label: 'Name: A to Z' },
    { value: 'quantity', label: 'Quantity' },
    { value: 'newest', label: 'Date added: newest' },
    { value: 'oldest', label: 'Date added: oldest' },
];

/** Compare nullable numbers, always sorting nulls last. */
function nullableCompare(
    a: number | null,
    b: number | null,
    dir: 1 | -1,
): number {
    if (a == null && b == null) {
        return 0;
    }

    if (a == null) {
        return 1;
    }

    if (b == null) {
        return -1;
    }

    return (a - b) * dir;
}

const gainClass = (n: number | null | undefined) =>
    n == null
        ? 'text-muted-foreground'
        : n > 0
          ? 'text-emerald-600 dark:text-emerald-400'
          : n < 0
            ? 'text-red-600 dark:text-red-400'
            : 'text-muted-foreground';

/** Signed money, e.g. "+$12.50" / "−$4.00"; "—" when null. */
function formatGain(cents: number | null, currency = 'USD'): string {
    if (cents == null) {
        return '—';
    }

    const sign = cents > 0 ? '+' : cents < 0 ? '−' : '';

    return `${sign}${formatMoney(Math.abs(cents), currency)}`;
}

/**
 * The holdings table (toolbar + rows + inline edits) shared by the collection
 * page and a single-folder page. Client-side search/set/sort with per-row edit,
 * for-sale toggle, move-to-collection and delete. When `folders` is provided a
 * folder filter is shown (the collection view); the folder page omits it since
 * it's already scoped.
 */
export function HoldingsTable({
    holdings,
    gradingCompanies,
    otherCollections,
    folders,
    onFilteredChange,
}: {
    holdings: Holding[];
    gradingCompanies: GradingCompanyOption[];
    otherCollections: { id: number; name: string }[];
    /** When set, renders a folder filter; omit on an already-folder-scoped page. */
    folders?: FolderRow[];
    /**
     * Reports the currently-visible rows (null when no filter is on) so the page
     * above can total them. The filters live here, so the summary has to be
     * pushed up rather than pulled.
     */
    onFilteredChange?: (visible: Holding[] | null) => void;
}) {
    const [editing, setEditing] = useState<Holding | null>(null);
    // Bulk selection + the actions it enables.
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [movingBulk, setMovingBulk] = useState(false);
    const [deletingBulk, setDeletingBulk] = useState(false);
    const [confirmRemove, setConfirmRemove] = useState<Holding | null>(null);
    const [busy, setBusy] = useState(false);
    const [moveChoice, setMoveChoice] = useState<CollectionFolderChoice>({
        collectionId: null,
        newCollectionName: null,
        folder: null,
    });
    const [q, setQ] = useState('');
    const [setFilter, setSetFilter] = useState(ALL);
    const [folderFilter, setFolderFilter] = useState(ALL);
    const [forSaleOnly, setForSaleOnly] = useState(false);
    const [sort, setSort] = useState('value_desc');

    const sets = useMemo(
        () =>
            Array.from(
                new Set(
                    holdings
                        .map((h) => h.catalog_item?.set?.name)
                        .filter((s): s is string => !!s),
                ),
            ).sort((a, b) => a.localeCompare(b)),
        [holdings],
    );

    const showFolderFilter = !!folders && folders.length > 0;

    const visible = useMemo(() => {
        const needle = q.trim().toLowerCase();

        const filtered = holdings.filter((h) => {
            if (forSaleOnly && !h.is_for_sale) {
                return false;
            }

            if (setFilter !== ALL && h.catalog_item?.set?.name !== setFilter) {
                return false;
            }

            if (
                showFolderFilter &&
                folderFilter !== ALL &&
                (h.folder ?? '') !== folderFilter
            ) {
                return false;
            }

            if (needle === '') {
                return true;
            }

            const ci = h.catalog_item;

            return (
                (ci?.display_name ?? ci?.name ?? '')
                    .toLowerCase()
                    .includes(needle) ||
                (ci?.number ?? '').toLowerCase().includes(needle) ||
                (ci?.set?.name ?? '').toLowerCase().includes(needle)
            );
        });

        const sorted = [...filtered];
        sorted.sort((a, b) => {
            switch (sort) {
                case 'value_asc':
                    return nullableCompare(a.market_value, b.market_value, 1);
                case 'pl_desc':
                    return nullableCompare(
                        a.unrealized_gain,
                        b.unrealized_gain,
                        -1,
                    );
                case 'pl_asc':
                    return nullableCompare(
                        a.unrealized_gain,
                        b.unrealized_gain,
                        1,
                    );
                case 'name':
                    return (a.catalog_item?.name ?? '').localeCompare(
                        b.catalog_item?.name ?? '',
                    );
                case 'quantity':
                    return b.quantity - a.quantity;
                case 'newest':
                    return (b.added_at ?? '').localeCompare(a.added_at ?? '');
                case 'oldest':
                    return (a.added_at ?? '').localeCompare(b.added_at ?? '');
                default:
                    return nullableCompare(a.market_value, b.market_value, -1);
            }
        });

        return sorted;
    }, [
        holdings,
        q,
        setFilter,
        folderFilter,
        forSaleOnly,
        sort,
        showFolderFilter,
    ]);

    const filtersActive =
        q.trim() !== '' ||
        setFilter !== ALL ||
        folderFilter !== ALL ||
        forSaleOnly;

    // Push the filtered view up so the page's totals can follow it. Sort order
    // doesn't change the set, so an unfiltered table reports null and the page
    // keeps the server's own figures.
    useEffect(() => {
        onFilteredChange?.(filtersActive ? visible : null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [visible, filtersActive]);

    const clearSelection = () => setSelected(new Set());

    const toggleOne = (id: number) =>
        setSelected((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    // The header checkbox selects/clears every row currently VISIBLE (so it
    // respects the active filters rather than silently grabbing the whole table).
    const allVisibleSelected =
        visible.length > 0 && visible.every((h) => selected.has(h.id));

    const toggleAllVisible = () =>
        setSelected((prev) => {
            const next = new Set(prev);
            visible.forEach((h) =>
                allVisibleSelected ? next.delete(h.id) : next.add(h.id),
            );

            return next;
        });

    const bulkMove = () => {
        const payload: Record<string, string | number | number[] | null> = {
            ids: [...selected],
            folder: moveChoice.folder,
        };

        // Only send one of the two — a null collection_id fails validation.
        if (moveChoice.newCollectionName) {
            payload.new_collection_name = moveChoice.newCollectionName;
        } else if (moveChoice.collectionId != null) {
            payload.collection_id = moveChoice.collectionId;
        }

        setBusy(true);
        router.patch('/collection/bulk', payload, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Moved ${selected.size} card(s).`);
                setMovingBulk(false);
                clearSelection();
            },
            onFinish: () => setBusy(false),
        });
    };

    const bulkDelete = () => {
        setBusy(true);
        router.delete('/collection/bulk', {
            data: { ids: [...selected] },
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Removed ${selected.size} card(s).`);
                setDeletingBulk(false);
                clearSelection();
            },
            onFinish: () => setBusy(false),
        });
    };

    const removeOne = (h: Holding) => {
        setBusy(true);
        router.delete(`/collection/${h.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Removed from your collection.');
                setConfirmRemove(null);
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <>
            <Card>
                <CardHeader className="gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <CardTitle className="text-sm">Holdings</CardTitle>
                        <span className="text-xs text-muted-foreground">
                            {visible.length.toLocaleString()} of{' '}
                            {holdings.length.toLocaleString()}
                        </span>
                    </div>

                    {/* Bulk action bar — only once something is selected. */}
                    {selected.size > 0 && (
                        <div className="flex flex-wrap items-center gap-2 rounded-md border border-primary/30 bg-primary/5 px-3 py-2">
                            <span className="text-sm font-medium">
                                {selected.size} selected
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8"
                                onClick={() => setMovingBulk(true)}
                            >
                                <FolderInput className="size-4" />
                                Move…
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-8 text-red-600 hover:text-red-600 dark:text-red-400"
                                onClick={() => setDeletingBulk(true)}
                            >
                                <Trash2 className="size-4" />
                                Remove
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="h-8"
                                onClick={clearSelection}
                            >
                                Clear
                            </Button>
                        </div>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative">
                            <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Search cards"
                                className="h-8 w-48 pl-8"
                            />
                        </div>

                        {sets.length > 1 && (
                            <Select
                                value={setFilter}
                                onValueChange={setSetFilter}
                            >
                                <SelectTrigger className="h-8 w-40">
                                    <SelectValue placeholder="All sets" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All sets
                                    </SelectItem>
                                    {sets.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {s}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}

                        {showFolderFilter && (
                            <Select
                                value={folderFilter}
                                onValueChange={setFolderFilter}
                            >
                                <SelectTrigger className="h-8 w-40">
                                    <SelectValue placeholder="All folders" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All folders
                                    </SelectItem>
                                    {folders!.map((f) => (
                                        <SelectItem key={f.id} value={f.name}>
                                            {f.name} ({f.items_count})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}

                        <Select value={sort} onValueChange={setSort}>
                            <SelectTrigger className="h-8 w-44">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {SORTS.map((s) => (
                                    <SelectItem key={s.value} value={s.value}>
                                        Sort: {s.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Button
                            type="button"
                            size="sm"
                            variant={forSaleOnly ? 'default' : 'outline'}
                            onClick={() => setForSaleOnly((v) => !v)}
                            className="h-8"
                        >
                            <Tag className="size-4" />
                            For sale
                        </Button>

                        {filtersActive && (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    setQ('');
                                    setSetFilter(ALL);
                                    setFolderFilter(ALL);
                                    setForSaleOnly(false);
                                }}
                                className="h-8"
                            >
                                Clear
                            </Button>
                        )}
                    </div>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs text-muted-foreground">
                            <tr className="border-b border-border">
                                <th className="w-8 py-2 pr-2">
                                    <Checkbox
                                        checked={allVisibleSelected}
                                        onCheckedChange={toggleAllVisible}
                                        aria-label="Select all shown"
                                    />
                                </th>
                                <th className="py-2 pr-3 font-medium">Card</th>
                                <th className="py-2 pr-3 font-medium">State</th>
                                <th className="py-2 pr-3 text-right font-medium">
                                    Qty
                                </th>
                                <th className="py-2 pr-3 text-right font-medium">
                                    Avg cost
                                </th>
                                <th className="py-2 pr-3 text-right font-medium">
                                    Cost basis
                                </th>
                                <th className="py-2 pr-3 text-right font-medium">
                                    Value
                                </th>
                                <th className="py-2 pr-3 text-right font-medium">
                                    P&L
                                </th>
                                <th className="py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {visible.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={9}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        No cards match your filters.
                                    </td>
                                </tr>
                            )}
                            {visible.map((h) => (
                                <tr
                                    key={h.id}
                                    className={cn(
                                        'border-b border-border/60 last:border-0',
                                        selected.has(h.id) && 'bg-primary/5',
                                    )}
                                >
                                    <td className="py-2 pr-2 align-middle">
                                        <Checkbox
                                            checked={selected.has(h.id)}
                                            onCheckedChange={() =>
                                                toggleOne(h.id)
                                            }
                                            aria-label={`Select ${h.catalog_item?.name ?? 'card'}`}
                                        />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <div className="flex items-center gap-2">
                                            {h.catalog_item?.image_url && (
                                                <img
                                                    src={
                                                        h.catalog_item.image_url
                                                    }
                                                    alt=""
                                                    className="h-10 w-auto rounded"
                                                    loading="lazy"
                                                />
                                            )}
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-1.5">
                                                    {h.catalog_item ? (
                                                        <Link
                                                            href={cardHref(
                                                                h.catalog_item,
                                                            )}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {h.catalog_item
                                                                .display_name ??
                                                                h.catalog_item
                                                                    .name}
                                                        </Link>
                                                    ) : (
                                                        <span className="font-medium">
                                                            Unknown
                                                        </span>
                                                    )}
                                                    {h.is_for_sale && (
                                                        <Badge className="bg-emerald-600/15 text-[10px] text-emerald-700 dark:text-emerald-400">
                                                            For sale
                                                        </Badge>
                                                    )}
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {h.catalog_item?.number}
                                                    {h.catalog_item?.set
                                                        ? ` · ${h.catalog_item.set.name}`
                                                        : ''}
                                                    {h.folder
                                                        ? ` · 📁 ${h.folder}`
                                                        : ''}
                                                </p>
                                                <InlineNotes
                                                    id={h.id}
                                                    value={h.notes}
                                                />
                                            </div>
                                        </div>
                                    </td>
                                    <td className="py-2 pr-3">
                                        <Badge variant="secondary">
                                            {h.state_label}
                                        </Badge>
                                    </td>
                                    <td className="py-2 pr-3 text-right">
                                        <InlineQty
                                            id={h.id}
                                            value={h.quantity}
                                        />
                                    </td>
                                    <td className="py-2 pr-3 text-right text-muted-foreground">
                                        {formatMoney(
                                            h.quantity > 0
                                                ? Math.round(
                                                      h.cost_basis / h.quantity,
                                                  )
                                                : 0,
                                            h.currency,
                                        )}
                                    </td>
                                    <td className="py-2 pr-3 text-right">
                                        {formatMoney(h.cost_basis, h.currency)}
                                    </td>
                                    <td className="py-2 pr-3 text-right font-medium">
                                        {formatMoney(
                                            h.market_value,
                                            h.currency,
                                        )}
                                    </td>
                                    <td
                                        className={cn(
                                            'py-2 pr-3 text-right',
                                            gainClass(h.unrealized_gain),
                                        )}
                                    >
                                        {formatGain(
                                            h.unrealized_gain,
                                            h.currency,
                                        )}
                                    </td>
                                    <td className="py-2 text-right">
                                        <div className="flex items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                onClick={() => setEditing(h)}
                                                className="text-muted-foreground hover:text-foreground"
                                                aria-label="Edit holding"
                                                title="Edit holding"
                                            >
                                                <Pencil className="size-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.patch(
                                                        `/collection/${h.id}`,
                                                        {
                                                            is_for_sale:
                                                                !h.is_for_sale,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () =>
                                                                toast.success(
                                                                    h.is_for_sale
                                                                        ? 'Removed from sale.'
                                                                        : 'Marked for sale.',
                                                                ),
                                                        },
                                                    )
                                                }
                                                className={cn(
                                                    'transition-colors',
                                                    h.is_for_sale
                                                        ? 'text-emerald-600 dark:text-emerald-400'
                                                        : 'text-muted-foreground hover:text-foreground',
                                                )}
                                                aria-label={
                                                    h.is_for_sale
                                                        ? 'Remove from sale'
                                                        : 'Mark for sale'
                                                }
                                                title={
                                                    h.is_for_sale
                                                        ? 'Remove from sale'
                                                        : 'Mark for sale'
                                                }
                                            >
                                                <Tag className="size-4" />
                                            </button>
                                            {otherCollections.length > 0 && (
                                                <select
                                                    aria-label="Move to collection"
                                                    title="Move to collection"
                                                    value=""
                                                    onChange={(e) =>
                                                        e.target.value &&
                                                        router.patch(
                                                            `/collection/${h.id}`,
                                                            {
                                                                collection_id:
                                                                    Number(
                                                                        e.target
                                                                            .value,
                                                                    ),
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                    className="h-7 rounded border border-border bg-background px-1 text-xs text-muted-foreground"
                                                >
                                                    <option value="">
                                                        Move…
                                                    </option>
                                                    {otherCollections.map(
                                                        (col) => (
                                                            <option
                                                                key={col.id}
                                                                value={col.id}
                                                            >
                                                                {col.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setConfirmRemove(h)
                                                }
                                                className="text-muted-foreground hover:text-red-600"
                                                aria-label="Remove holding"
                                            >
                                                <Trash2 className="size-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <EditHoldingDialog
                holding={
                    editing
                        ? {
                              id: editing.id,
                              collection_id: editing.collection_id,
                              name:
                                  editing.catalog_item?.display_name ??
                                  editing.catalog_item?.name ??
                                  'Holding',
                              condition: editing.condition,
                              grade: editing.grade,
                              grading_company: editing.grading_company
                                  ? { id: editing.grading_company.id }
                                  : null,
                              quantity: editing.quantity,
                              is_for_sale: editing.is_for_sale,
                              notes: editing.notes,
                              folder: editing.folder,
                          }
                        : null
                }
                gradingCompanies={gradingCompanies}
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
            />

            {/* Bulk move — same collection + folder picker used everywhere else. */}
            <Dialog open={movingBulk} onOpenChange={setMovingBulk}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Move {selected.size} card
                            {selected.size === 1 ? '' : 's'}
                        </DialogTitle>
                        <DialogDescription>
                            Pick where these cards should live. The folder is
                            scoped to the collection you choose.
                        </DialogDescription>
                    </DialogHeader>

                    {movingBulk && (
                        <CollectionFolderPicker
                            open={movingBulk}
                            onChange={setMoveChoice}
                        />
                    )}

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setMovingBulk(false)}
                            disabled={busy}
                        >
                            Cancel
                        </Button>
                        <Button onClick={bulkMove} disabled={busy}>
                            Move
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deletingBulk}
                onOpenChange={setDeletingBulk}
                title={`Remove ${selected.size} card${selected.size === 1 ? '' : 's'}?`}
                description="They'll be removed from your collection along with their cost-basis history. This can't be undone."
                confirmLabel="Remove"
                destructive
                busy={busy}
                onConfirm={bulkDelete}
            />

            <ConfirmDialog
                open={confirmRemove !== null}
                onOpenChange={(o) => !o && setConfirmRemove(null)}
                title="Remove this card?"
                description={
                    confirmRemove
                        ? `"${confirmRemove.catalog_item?.display_name ?? confirmRemove.catalog_item?.name ?? 'This holding'}" will be removed from your collection along with its cost-basis history.`
                        : undefined
                }
                confirmLabel="Remove"
                destructive
                busy={busy}
                onConfirm={() => confirmRemove && removeOne(confirmRemove)}
            />
        </>
    );
}

/**
 * Enter commits, Escape cancels, blur commits. Blur is the single commit path —
 * Enter/Escape just blur the field (Escape sets a skip flag first) so we never
 * double-submit or save a cancelled edit.
 */
function inlineKeyDown(
    e: React.KeyboardEvent<HTMLInputElement>,
    cancel: React.MutableRefObject<boolean>,
) {
    if (e.key === 'Enter') {
        e.currentTarget.blur();
    } else if (e.key === 'Escape') {
        cancel.current = true;
        e.currentTarget.blur();
    }
}

/** Click-to-edit quantity. */
function InlineQty({ id, value }: { id: number; value: number }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(String(value));
    const cancel = useRef(false);

    const start = () => {
        setDraft(String(value));
        setEditing(true);
    };

    const commit = () => {
        setEditing(false);

        if (cancel.current) {
            cancel.current = false;

            return;
        }

        const next = Math.max(0, Math.min(100000, parseInt(draft, 10) || 0));

        if (next !== value) {
            router.patch(
                `/collection/${id}`,
                { quantity: next },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        if (next === 0) {
                            toast.success('Removed from your collection.');
                        }
                    },
                },
            );
        }
    };

    if (!editing) {
        return (
            <button
                type="button"
                onClick={start}
                className="rounded px-1.5 py-0.5 tabular-nums hover:bg-muted"
                title="Edit quantity"
            >
                {value}
            </button>
        );
    }

    return (
        <input
            type="number"
            min={0}
            autoFocus
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={commit}
            onKeyDown={(e) => inlineKeyDown(e, cancel)}
            className="h-7 w-16 rounded border border-border bg-background px-1.5 text-right text-sm tabular-nums"
        />
    );
}

/** Click-to-edit holding note. */
function InlineNotes({ id, value }: { id: number; value: string | null }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(value ?? '');
    const cancel = useRef(false);

    const start = () => {
        setDraft(value ?? '');
        setEditing(true);
    };

    const commit = () => {
        setEditing(false);

        if (cancel.current) {
            cancel.current = false;

            return;
        }

        const next = draft.trim();

        if (next !== (value ?? '')) {
            router.patch(
                `/collection/${id}`,
                { notes: next === '' ? null : next.slice(0, 1000) },
                {
                    preserveScroll: true,
                    onSuccess: () => toast.success('Note saved.'),
                },
            );
        }
    };

    if (editing) {
        return (
            <input
                type="text"
                autoFocus
                maxLength={1000}
                value={draft}
                placeholder="Add a note…"
                onChange={(e) => setDraft(e.target.value)}
                onBlur={commit}
                onKeyDown={(e) => inlineKeyDown(e, cancel)}
                className="mt-0.5 h-6 w-full max-w-xs rounded border border-border bg-background px-1.5 text-xs"
            />
        );
    }

    return (
        <button
            type="button"
            onClick={start}
            className="mt-0.5 flex max-w-xs items-center gap-1 text-left text-xs text-muted-foreground hover:text-foreground"
            title="Edit note"
        >
            <StickyNote className="size-3 shrink-0" />
            {value ? (
                <span className="truncate">{value}</span>
            ) : (
                <span className="italic">Add note</span>
            )}
        </button>
    );
}
