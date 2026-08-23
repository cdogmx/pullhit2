import { Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    Link2,
    Link2Off,
    Loader2,
    RefreshCw,
    Search,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { AdminPagination } from '@/types/admin';

type Coverage = {
    rarity: number;
    type: number;
    image: number;
    number: number;
    value: number;
};

type Row = {
    id: number;
    name: string;
    slug: string;
    code: string | null;
    series: string | null;
    brand: string | null;
    brand_slug: string | null;
    language: string | null;
    released_at: string | null;
    items: number;
    singles: number;
    coverage: Coverage;
    health: number;
    group_id: string | null;
    backfillable: boolean;
};

type Candidate = {
    group_id: string;
    name: string;
    abbreviation: string;
    score: number;
};

type SortKey =
    | 'health'
    | 'items'
    | 'rarity'
    | 'type'
    | 'image'
    | 'number'
    | 'value'
    | 'name'
    | 'released';

type Filters = {
    q: string;
    brand: string;
    language: string;
    only: '' | 'problems' | 'unlinked';
    sort: SortKey;
    direction: 'asc' | 'desc';
};

type Props = {
    rows: Row[];
    pagination: AdminPagination;
    filters: Filters;
    options: {
        brands: { value: string; label: string }[];
        languages: string[];
        backfillable: string[];
    };
};

const ALL = '__all__';

/**
 * The five facets, in the order a card is usually missing them. Each is the
 * share of the set that carries the fact at all — not a quality score.
 */
const FACETS: { key: keyof Coverage; label: string; hint: string }[] = [
    { key: 'rarity', label: 'Rarity', hint: 'singles with a rarity' },
    { key: 'type', label: 'Type', hint: 'singles with a card type / colour' },
    { key: 'number', label: 'Number', hint: 'singles with a collector number' },
    { key: 'image', label: 'Image', hint: 'items with an image' },
    { key: 'value', label: 'Value', hint: 'items with a market value' },
];

const COLUMNS: { key: SortKey; label: string; align?: 'right' }[] = [
    { key: 'name', label: 'Set' },
    { key: 'released', label: 'Released' },
    { key: 'items', label: 'Cards', align: 'right' },
    { key: 'health', label: 'Described', align: 'right' },
];

/** Below this a set is worth a person's attention rather than a glance. */
const POOR = 60;
const GOOD = 90;

function tone(pct: number): string {
    if (pct >= GOOD) {
        return 'bg-emerald-500';
    }

    if (pct >= POOR) {
        return 'bg-amber-500';
    }

    return 'bg-rose-500';
}

/** A coverage cell: the bar carries the reading, the number confirms it. */
function Meter({ pct, title }: { pct: number; title: string }) {
    return (
        <td className="px-2 py-2" title={title}>
            <div className="flex items-center gap-2">
                <div className="h-1.5 w-full min-w-10 overflow-hidden rounded-full bg-muted">
                    <div
                        className={cn('h-full rounded-full', tone(pct))}
                        style={{ width: `${pct}%` }}
                    />
                </div>
                <span className="w-9 shrink-0 text-right text-xs text-muted-foreground tabular-nums">
                    {pct}%
                </span>
            </div>
        </td>
    );
}

export default function AdminSetHealth({
    rows,
    pagination,
    filters,
    options,
}: Props) {
    const [q, setQ] = useState(filters.q);
    const [busy, setBusy] = useState<number | null>(null);
    const [linking, setLinking] = useState<Row | null>(null);
    const [candidates, setCandidates] = useState<Candidate[] | null>(null);
    const [reason, setReason] = useState<string | null>(null);
    const [manual, setManual] = useState('');

    const apply = (changes: Record<string, string | number> = {}) =>
        router.get(
            '/admin/set-health',
            { ...filters, page: 1, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    useEffect(() => {
        if (q === filters.q) {
            return;
        }

        const id = window.setTimeout(() => apply({ q }), 350);

        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    const sortBy = (key: SortKey) =>
        apply(
            key === filters.sort
                ? { direction: filters.direction === 'asc' ? 'desc' : 'asc' }
                : {
                      sort: key,
                      // Worst-first for the scores, natural order for the text.
                      direction:
                          key === 'name' || key === 'released' ? 'asc' : 'desc',
                  },
        );

    const openLink = async (row: Row) => {
        setLinking(row);
        setCandidates(null);
        setReason(null);
        setManual(row.group_id ?? '');

        const res = await fetch(`/admin/set-health/${row.id}/candidates`, {
            headers: { Accept: 'application/json' },
        });
        const body = await res.json();

        setCandidates(body.candidates ?? []);
        setReason(body.reason ?? null);
    };

    const saveLink = (row: Row, groupId: string | null) => {
        setBusy(row.id);
        router.post(
            `/admin/set-health/${row.id}/link`,
            { group_id: groupId },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(null);
                    setLinking(null);
                },
            },
        );
    };

    const backfill = (row: Row) => {
        setBusy(row.id);
        router.post(
            `/admin/set-health/${row.id}/backfill`,
            {},
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    const SortIcon = ({ column }: { column: SortKey }) => {
        if (column !== filters.sort) {
            return <ChevronsUpDown className="size-3 opacity-40" />;
        }

        return filters.direction === 'asc' ? (
            <ArrowUp className="size-3" />
        ) : (
            <ArrowDown className="size-3" />
        );
    };

    return (
        <>
            <Head title="Admin · Set health" />
            <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-bold tracking-tight">
                        Set health
                    </h1>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        How completely each set is described. The catalog is
                        built from several sources and they carry cards to very
                        different depths — a price-only feed gives no rarity and
                        no card type at all — so gaps here are what make filters
                        come up short. Point a set at its TCGplayer group, then
                        pull the facts down.
                    </p>
                </div>

                {/* Controls */}
                <div className="flex flex-wrap items-end gap-3 rounded-xl border border-border p-3">
                    <div className="grid gap-1">
                        <Label htmlFor="q" className="text-xs">
                            Search
                        </Label>
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                id="q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Set, code, or series…"
                                className="h-9 w-60 pl-8"
                            />
                        </div>
                    </div>

                    <div className="grid gap-1">
                        <Label className="text-xs">Brand</Label>
                        <Select
                            value={filters.brand || ALL}
                            onValueChange={(v) =>
                                apply({ brand: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="h-9 w-44">
                                <SelectValue placeholder="All brands" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All brands</SelectItem>
                                {options.brands.map((b) => (
                                    <SelectItem key={b.value} value={b.value}>
                                        {b.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1">
                        <Label className="text-xs">Language</Label>
                        <Select
                            value={filters.language || ALL}
                            onValueChange={(v) =>
                                apply({ language: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="h-9 w-28">
                                <SelectValue placeholder="Any" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Any</SelectItem>
                                {options.languages.map((l) => (
                                    <SelectItem key={l} value={l}>
                                        {l.toUpperCase()}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1">
                        <Label className="text-xs">Show</Label>
                        <Select
                            value={filters.only || ALL}
                            onValueChange={(v) =>
                                apply({ only: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="h-9 w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All sets</SelectItem>
                                <SelectItem value="problems">
                                    Incompletely described
                                </SelectItem>
                                <SelectItem value="unlinked">
                                    No upstream group
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="ml-auto text-sm text-muted-foreground">
                        {pagination.total.toLocaleString()} sets
                    </div>
                </div>

                {/* Table */}
                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="border-b border-border bg-muted/40 text-xs">
                            <tr>
                                {COLUMNS.map((c) => (
                                    <th
                                        key={c.key}
                                        className={cn(
                                            'px-3 py-2 font-medium',
                                            c.align === 'right'
                                                ? 'text-right'
                                                : 'text-left',
                                        )}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => sortBy(c.key)}
                                            className={cn(
                                                'inline-flex items-center gap-1 hover:text-foreground',
                                                c.key === filters.sort
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {c.label}
                                            <SortIcon column={c.key} />
                                        </button>
                                    </th>
                                ))}
                                {FACETS.map((f) => (
                                    <th
                                        key={f.key}
                                        className="px-2 py-2 text-left font-medium"
                                    >
                                        <button
                                            type="button"
                                            onClick={() =>
                                                sortBy(f.key as SortKey)
                                            }
                                            title={f.hint}
                                            className={cn(
                                                'inline-flex items-center gap-1 hover:text-foreground',
                                                f.key === filters.sort
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {f.label}
                                            <SortIcon
                                                column={f.key as SortKey}
                                            />
                                        </button>
                                    </th>
                                ))}
                                <th className="px-3 py-2 text-right font-medium text-muted-foreground">
                                    Fix
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={
                                            COLUMNS.length + FACETS.length + 1
                                        }
                                        className="px-3 py-10 text-center text-muted-foreground"
                                    >
                                        No sets match these filters.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((r) => (
                                    <tr
                                        key={r.id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-3 py-2">
                                            <div className="font-medium">
                                                {r.name}
                                            </div>
                                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                {r.brand}
                                                {r.code && (
                                                    <span className="rounded bg-muted px-1 font-mono">
                                                        {r.code}
                                                    </span>
                                                )}
                                                {r.language && (
                                                    <span className="uppercase">
                                                        {r.language}
                                                    </span>
                                                )}
                                                {!r.group_id &&
                                                    r.backfillable && (
                                                        <Badge
                                                            variant="outline"
                                                            className="h-4 gap-1 px-1 text-[10px] font-normal text-amber-600 dark:text-amber-500"
                                                        >
                                                            <Link2Off className="size-2.5" />
                                                            unlinked
                                                        </Badge>
                                                    )}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 text-xs whitespace-nowrap text-muted-foreground">
                                            {r.released_at ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {r.items.toLocaleString()}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <span
                                                className={cn(
                                                    'inline-block rounded px-1.5 py-0.5 text-xs font-semibold tabular-nums',
                                                    r.health >= GOOD
                                                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                        : r.health >= POOR
                                                          ? 'bg-amber-500/10 text-amber-600 dark:text-amber-500'
                                                          : 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
                                                )}
                                            >
                                                {r.health}%
                                            </span>
                                        </td>
                                        {FACETS.map((f) => (
                                            <Meter
                                                key={f.key}
                                                pct={r.coverage[f.key]}
                                                title={`${r.coverage[f.key]}% of ${f.hint}`}
                                            />
                                        ))}
                                        <td className="px-3 py-2">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 gap-1 px-2 text-xs"
                                                    disabled={!r.backfillable}
                                                    onClick={() => openLink(r)}
                                                    title={
                                                        r.group_id
                                                            ? `Linked to group ${r.group_id}`
                                                            : 'Link to a TCGplayer group'
                                                    }
                                                >
                                                    <Link2
                                                        className={cn(
                                                            'size-3.5',
                                                            r.group_id &&
                                                                'text-emerald-600 dark:text-emerald-400',
                                                        )}
                                                    />
                                                    Link
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-7 gap-1 px-2 text-xs"
                                                    disabled={
                                                        !r.backfillable ||
                                                        busy === r.id
                                                    }
                                                    onClick={() => backfill(r)}
                                                    title="Pull rarity and card type from TCGCSV"
                                                >
                                                    {busy === r.id ? (
                                                        <Loader2 className="size-3.5 animate-spin" />
                                                    ) : (
                                                        <RefreshCw className="size-3.5" />
                                                    )}
                                                    Backfill
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pagination.page <= 1}
                            onClick={() => apply({ page: pagination.page - 1 })}
                        >
                            Previous
                        </Button>
                        <span className="text-muted-foreground">
                            Page {pagination.page} of {pagination.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pagination.page >= pagination.last_page}
                            onClick={() => apply({ page: pagination.page + 1 })}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>

            {/* Link picker. Candidates are a shortlist for a person, never an
                automatic pairing — sibling starter decks score ~95% against each
                other and are different products. */}
            <Dialog
                open={linking !== null}
                onOpenChange={(open) => !open && setLinking(null)}
            >
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{linking?.name}</DialogTitle>
                        <DialogDescription>
                            Choose the TCGplayer group this set corresponds to.
                            Names are ranked by similarity — check it before you
                            pick, since sibling decks look nearly identical.
                        </DialogDescription>
                    </DialogHeader>

                    {reason && (
                        <p className="text-sm text-muted-foreground">
                            {reason}
                        </p>
                    )}

                    {candidates === null && !reason ? (
                        <div className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" />
                            Fetching groups…
                        </div>
                    ) : (
                        <div className="max-h-72 space-y-1 overflow-y-auto">
                            {(candidates ?? []).map((c) => (
                                <button
                                    key={c.group_id}
                                    type="button"
                                    onClick={() =>
                                        linking && saveLink(linking, c.group_id)
                                    }
                                    className={cn(
                                        'flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm hover:bg-muted',
                                        linking?.group_id === c.group_id
                                            ? 'border-emerald-500/50 bg-emerald-500/5'
                                            : 'border-border',
                                    )}
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate font-medium">
                                            {c.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {c.abbreviation || '—'} · group{' '}
                                            {c.group_id}
                                        </div>
                                    </div>
                                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                        {c.score}%
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}

                    <div className="flex items-center justify-between gap-2 border-t border-border pt-3">
                        <div className="flex items-center gap-2">
                            <Input
                                value={manual}
                                onChange={(e) => setManual(e.target.value)}
                                placeholder="Group ID"
                                className="h-8 w-28"
                            />
                            <Button
                                size="sm"
                                variant="secondary"
                                disabled={!manual.trim()}
                                onClick={() =>
                                    linking && saveLink(linking, manual.trim())
                                }
                            >
                                Set
                            </Button>
                        </div>
                        {linking?.group_id && (
                            <Button
                                size="sm"
                                variant="ghost"
                                className="text-muted-foreground"
                                onClick={() =>
                                    linking && saveLink(linking, null)
                                }
                            >
                                Clear link
                            </Button>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

AdminSetHealth.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Set health', href: '/admin/set-health' },
    ],
};
