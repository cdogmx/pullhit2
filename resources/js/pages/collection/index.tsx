import { Head, Link, router } from '@inertiajs/react';
import {
    Download,
    Pencil,
    Search,
    StickyNote,
    Tag,
    Trash2,
    Upload,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { CollectionFolders } from '@/components/collection/collection-folders';
import { EditHoldingDialog } from '@/components/collection/edit-holding-dialog';
import { ListTabs } from '@/components/shared/list-tabs';
import type { ListSummary } from '@/components/shared/list-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import type {
    Allocation,
    GradingCompanyOption,
    Holding,
    PortfolioMover,
    PortfolioSummary,
} from '@/types';

export type FolderRow = {
    id: number;
    name: string;
    slug: string;
    is_public: boolean;
    items_count: number;
    public_url: string | null;
};

type Props = {
    collections: ListSummary[];
    activeCollection: string;
    collectionLimit: number | null;
    holdings: Holding[];
    summary: PortfolioSummary;
    allocation: Allocation[];
    gainers: PortfolioMover[];
    decliners: PortfolioMover[];
    publicUrl: string | null;
    folders: FolderRow[];
    gradingCompanies: GradingCompanyOption[];
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

export default function CollectionIndex({
    collections,
    activeCollection,
    collectionLimit,
    holdings,
    summary,
    allocation,
    gainers,
    decliners,
    publicUrl,
    folders,
    gradingCompanies,
}: Props) {
    const c = summary.currency;
    const otherCollections = collections.filter(
        (x) => x.slug !== activeCollection,
    );

    const [editing, setEditing] = useState<Holding | null>(null);
    const [q, setQ] = useState('');
    const [setFilter, setSetFilter] = useState(ALL);
    const [folderFilter, setFolderFilter] = useState(ALL);
    const [forSaleOnly, setForSaleOnly] = useState(false);
    const [sort, setSort] = useState('value_desc');

    // Sets present in this collection, for the filter dropdown.
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

    const visible = useMemo(() => {
        const needle = q.trim().toLowerCase();

        const filtered = holdings.filter((h) => {
            if (forSaleOnly && !h.is_for_sale) {
                return false;
            }

            if (setFilter !== ALL && h.catalog_item?.set?.name !== setFilter) {
                return false;
            }

            if (folderFilter !== ALL && (h.folder ?? '') !== folderFilter) {
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
                    return nullableCompare(a.unrealized_gain, b.unrealized_gain, -1);
                case 'pl_asc':
                    return nullableCompare(a.unrealized_gain, b.unrealized_gain, 1);
                case 'name':
                    return (
                        a.catalog_item?.name ?? ''
                    ).localeCompare(b.catalog_item?.name ?? '');
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
    }, [holdings, q, setFilter, folderFilter, forSaleOnly, sort]);

    const filtersActive =
        q.trim() !== '' ||
        setFilter !== ALL ||
        folderFilter !== ALL ||
        forSaleOnly;

    return (
        <>
            <Head title="Collection" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Your collection
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
                    </div>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href="/collection/import">
                                <Upload className="size-4" />
                                Import
                            </Link>
                        </Button>
                        {holdings.length > 0 && (
                            <Button asChild variant="outline" size="sm">
                                <a href="/collection/export">
                                    <Download className="size-4" />
                                    Export CSV
                                </a>
                            </Button>
                        )}
                    </div>
                </div>

                <ListTabs
                    lists={collections}
                    active={activeCollection}
                    limit={collectionLimit}
                    basePath="/collection"
                    queryKey="collection"
                    entityBase="/collections"
                    noun="collection"
                />

                {/* Summary */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard label="Portfolio value">
                        {formatMoney(summary.total_value, c)}
                    </SummaryCard>
                    <SummaryCard label="Cost basis">
                        {formatMoney(summary.total_cost, c)}
                    </SummaryCard>
                    <SummaryCard label="Unrealized P&L">
                        <span className={gainClass(summary.unrealized_gain)}>
                            {formatGain(summary.unrealized_gain, c)}
                            {summary.unrealized_pct != null && (
                                <span className="ml-1 text-sm font-normal">
                                    ({summary.unrealized_pct > 0 ? '+' : ''}
                                    {summary.unrealized_pct}%)
                                </span>
                            )}
                        </span>
                    </SummaryCard>
                    <SummaryCard label="Cards">
                        {summary.card_count}
                        <span className="ml-1 text-sm font-normal text-muted-foreground">
                            in {summary.item_count}{' '}
                            {summary.item_count === 1 ? 'holding' : 'holdings'}
                        </span>
                    </SummaryCard>
                </div>

                {holdings.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <p>Your collection is empty.</p>
                            <Link
                                href="/browse"
                                className="mt-2 inline-block text-sm font-medium text-primary hover:underline"
                            >
                                Browse the catalog to add cards →
                            </Link>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {/* Allocation + movers — equal-height cards (grid stretch). */}
                        <div className="grid items-stretch gap-4 lg:grid-cols-3">
                            <Card className="flex h-full flex-col lg:col-span-1">
                                <CardHeader>
                                    <CardTitle className="text-sm">Allocation by set</CardTitle>
                                </CardHeader>
                                <CardContent className="max-h-72 flex-1 space-y-2 overflow-y-auto">
                                    {allocation.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            No valued holdings yet.
                                        </p>
                                    )}
                                    {allocation.map((a) => (
                                        <div key={a.label}>
                                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                                <span className="min-w-0 truncate">
                                                    {a.brand && (
                                                        <span className="text-muted-foreground">
                                                            {a.brand}{' '}
                                                            <span aria-hidden>
                                                                →
                                                            </span>{' '}
                                                        </span>
                                                    )}
                                                    {a.label}
                                                </span>
                                                <span className="shrink-0 text-muted-foreground">
                                                    {formatMoney(a.value, c)} · {a.pct}%
                                                </span>
                                            </div>
                                            <div className="mt-1 h-1.5 rounded-full bg-muted">
                                                <div
                                                    className="h-1.5 rounded-full bg-primary"
                                                    style={{ width: `${a.pct}%` }}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>

                            <MoversCard title="Top gainers" movers={gainers} currency={c} />
                            <MoversCard title="Top decliners" movers={decliners} currency={c} />
                        </div>

                        <CollectionFolders folders={folders} />

                        {/* Holdings */}
                        <Card>
                            <CardHeader className="gap-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <CardTitle className="text-sm">
                                        Holdings
                                    </CardTitle>
                                    <span className="text-xs text-muted-foreground">
                                        {visible.length.toLocaleString()} of{' '}
                                        {holdings.length.toLocaleString()}
                                    </span>
                                </div>
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

                                    {folders.length > 0 && (
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
                                                {folders.map((f) => (
                                                    <SelectItem
                                                        key={f.id}
                                                        value={f.name}
                                                    >
                                                        {f.name} ({f.items_count}
                                                        )
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
                                                <SelectItem
                                                    key={s.value}
                                                    value={s.value}
                                                >
                                                    Sort: {s.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={forSaleOnly ? 'default' : 'outline'}
                                        onClick={() =>
                                            setForSaleOnly((v) => !v)
                                        }
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
                                            <th className="py-2 pr-3 font-medium">Card</th>
                                            <th className="py-2 pr-3 font-medium">State</th>
                                            <th className="py-2 pr-3 text-right font-medium">Qty</th>
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
                                                    colSpan={8}
                                                    className="py-8 text-center text-muted-foreground"
                                                >
                                                    No cards match your filters.
                                                </td>
                                            </tr>
                                        )}
                                        {visible.map((h) => (
                                            <tr
                                                key={h.id}
                                                className="border-b border-border/60 last:border-0"
                                            >
                                                <td className="py-2 pr-3">
                                                    <div className="flex items-center gap-2">
                                                        {h.catalog_item?.image_url && (
                                                            <img
                                                                src={h.catalog_item.image_url}
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
                                                                        {h
                                                                            .catalog_item
                                                                            .display_name ??
                                                                            h
                                                                                .catalog_item
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
                                                    {formatMoney(h.market_value, h.currency)}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'py-2 pr-3 text-right',
                                                        gainClass(h.unrealized_gain),
                                                    )}
                                                >
                                                    {formatGain(h.unrealized_gain, h.currency)}
                                                </td>
                                                <td className="py-2 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setEditing(h)
                                                            }
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
                                                                        preserveScroll:
                                                                            true,
                                                                        onSuccess:
                                                                            () =>
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
                                                        {otherCollections.length >
                                                            0 && (
                                                            <select
                                                                aria-label="Move to collection"
                                                                title="Move to collection"
                                                                value=""
                                                                onChange={(e) =>
                                                                    e.target
                                                                        .value &&
                                                                    router.patch(
                                                                        `/collection/${h.id}`,
                                                                        {
                                                                            collection_id:
                                                                                Number(
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                                ),
                                                                        },
                                                                        {
                                                                            preserveScroll:
                                                                                true,
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
                                                                            key={
                                                                                col.id
                                                                            }
                                                                            value={
                                                                                col.id
                                                                            }
                                                                        >
                                                                            {
                                                                                col.name
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        )}
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.delete(
                                                                    `/collection/${h.id}`,
                                                                    {
                                                                        preserveScroll:
                                                                            true,
                                                                    },
                                                                )
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
                    </>
                )}
            </div>

            <EditHoldingDialog
                holding={
                    editing
                        ? {
                              id: editing.id,
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
                collections={otherCollections.map((col) => ({
                    id: col.id,
                    name: col.name,
                }))}
                gradingCompanies={gradingCompanies}
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
            />
        </>
    );
}

function SummaryCard({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <Card>
            <CardContent className="pt-6">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="mt-1 text-2xl font-bold tracking-tight">{children}</p>
            </CardContent>
        </Card>
    );
}

function MoversCard({
    title,
    movers,
    currency,
}: {
    title: string;
    movers: PortfolioMover[];
    currency: string;
}) {
    return (
        <Card className="flex h-full flex-col">
            <CardHeader>
                <CardTitle className="text-sm">{title}</CardTitle>
            </CardHeader>
            <CardContent className="max-h-72 flex-1 space-y-2 overflow-y-auto">
                {movers.length === 0 && (
                    <p className="text-sm text-muted-foreground">Nothing yet.</p>
                )}
                {movers.map((m) => (
                    <div key={m.id} className="flex items-center justify-between text-sm">
                        <span className="min-w-0 truncate">
                            {m.name}{' '}
                            <span className="text-xs text-muted-foreground">{m.state}</span>
                        </span>
                        <span className={cn('shrink-0', gainClass(m.gain))}>
                            {formatGain(m.gain, currency)}
                            {m.pct != null && (
                                <span className="ml-1 text-xs">
                                    ({m.pct > 0 ? '+' : ''}
                                    {m.pct}%)
                                </span>
                            )}
                        </span>
                    </div>
                ))}
            </CardContent>
        </Card>
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
                { preserveScroll: true },
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

CollectionIndex.layout = {
    breadcrumbs: [{ title: 'Collection', href: '/collection' }],
};
