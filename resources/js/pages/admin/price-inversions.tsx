import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    ExternalLink,
    ImageOff,
    Search,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AdminPagination } from '@/types';

type Row = {
    id: number;
    name: string;
    number: string | null;
    set: string | null;
    brand: string | null;
    image_url: string | null;
    url: string;
    grader: string | null;
    grade: number;
    state: string;
    /** Money fields are integer minor units (cents). */
    graded: number;
    graded_n: number;
    graded_confidence: number;
    raw: number;
    raw_n: number;
    raw_confidence: number;
    gap: number;
    ratio: number | null;
};

type SortKey =
    | 'gap'
    | 'ratio'
    | 'raw'
    | 'graded'
    | 'grade'
    | 'name'
    | 'set'
    | 'sales';

type Filters = {
    q: string;
    brand: string;
    min_grade: number;
    min_sales: number;
    min_gap: number;
    sort: SortKey;
    direction: 'asc' | 'desc';
};

type Props = {
    rows: Row[];
    pagination: AdminPagination;
    filters: Filters;
    options: { brands: { value: string; label: string }[] };
};

const ALL = '__all__';

/** Sortable columns, in the order they appear in the table. */
const COLUMNS: { key: SortKey; label: string; align?: 'right' }[] = [
    { key: 'name', label: 'Card' },
    { key: 'grade', label: 'Slab' },
    { key: 'graded', label: 'Graded', align: 'right' },
    { key: 'raw', label: 'Raw (NM)', align: 'right' },
    { key: 'gap', label: 'Shortfall', align: 'right' },
    { key: 'ratio', label: 'Graded ÷ raw', align: 'right' },
];

/**
 * A confidence low enough that the number is barely evidence. Both sides are
 * shown so the reader can see which one is thin — an inversion between two
 * one-sale values is noise, not a finding.
 */
const THIN = 0.3;

export default function AdminPriceInversions({
    rows,
    pagination,
    filters,
    options,
}: Props) {
    const [q, setQ] = useState(filters.q);

    const apply = (changes: Record<string, string | number> = {}) =>
        router.get(
            '/admin/price-inversions',
            { ...filters, page: 1, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // Debounce the search so typing doesn't fire a request per keystroke.
    useEffect(() => {
        if (q === filters.q) {
            return;
        }

        const id = window.setTimeout(() => apply({ q }), 350);

        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    // Clicking the active column flips direction; a new column starts descending,
    // except the text columns, which read naturally A→Z.
    const sortBy = (key: SortKey) =>
        apply(
            key === filters.sort
                ? { direction: filters.direction === 'asc' ? 'desc' : 'asc' }
                : {
                      sort: key,
                      direction:
                          key === 'name' || key === 'set' ? 'asc' : 'desc',
                  },
        );

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
            <Head title="Admin · Price inversions" />
            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-bold tracking-tight">
                        Price inversions
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Cards whose graded value sits{' '}
                        <span className="font-medium text-foreground">
                            below their own raw value
                        </span>
                        . A slab at 8 or better should not be worth less than
                        the loose card, so one side of the pair is usually wrong
                        — most often a raw value that has swallowed a comp for a
                        different card. Real sold data only.
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
                                placeholder="Card, number, or set…"
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
                            <SelectTrigger className="h-9 w-40">
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
                        <Label htmlFor="min_grade" className="text-xs">
                            Grade at least
                        </Label>
                        <Select
                            value={String(filters.min_grade)}
                            onValueChange={(v) => apply({ min_grade: v })}
                        >
                            <SelectTrigger id="min_grade" className="h-9 w-24">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {[7, 8, 9, 9.5, 10].map((g) => (
                                    <SelectItem key={g} value={String(g)}>
                                        {g}+
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="min_sales" className="text-xs">
                            Sales each side
                        </Label>
                        <Select
                            value={String(filters.min_sales)}
                            onValueChange={(v) => apply({ min_sales: v })}
                        >
                            <SelectTrigger id="min_sales" className="h-9 w-24">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {[1, 2, 3, 5, 10].map((n) => (
                                    <SelectItem key={n} value={String(n)}>
                                        {n}+
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="min_gap" className="text-xs">
                            Min shortfall ($)
                        </Label>
                        <Input
                            id="min_gap"
                            defaultValue={String(filters.min_gap)}
                            onBlur={(e) => apply({ min_gap: e.target.value })}
                            className="h-9 w-28"
                            inputMode="decimal"
                        />
                    </div>

                    <div className="ml-auto self-center text-sm text-muted-foreground">
                        {pagination.total.toLocaleString()} inversion
                        {pagination.total === 1 ? '' : 's'}
                    </div>
                </div>

                {/* Table */}
                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                {COLUMNS.map((c) => (
                                    <th
                                        key={c.key}
                                        className={cn(
                                            'px-3 py-2 font-medium',
                                            c.align === 'right' && 'text-right',
                                        )}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => sortBy(c.key)}
                                            className={cn(
                                                'inline-flex items-center gap-1 rounded hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                                c.key === filters.sort &&
                                                    'text-foreground',
                                            )}
                                            aria-label={`Sort by ${c.label}`}
                                        >
                                            {c.label}
                                            <SortIcon column={c.key} />
                                        </button>
                                    </th>
                                ))}
                                <th className="w-8" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border/60">
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={COLUMNS.length + 1}
                                        className="px-3 py-10 text-center text-muted-foreground"
                                    >
                                        No inversions match these filters —
                                        which is the healthy answer.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((r) => (
                                    <tr
                                        key={`${r.id}-${r.state}`}
                                        className="hover:bg-accent/30"
                                    >
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-2">
                                                <span className="flex h-12 w-9 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                                                    {r.image_url ? (
                                                        <img
                                                            src={r.image_url}
                                                            alt=""
                                                            loading="lazy"
                                                            className="size-full object-contain"
                                                        />
                                                    ) : (
                                                        <ImageOff className="size-4 text-muted-foreground" />
                                                    )}
                                                </span>
                                                <div className="min-w-0">
                                                    <div className="truncate font-medium">
                                                        {r.name}
                                                    </div>
                                                    <div className="truncate text-xs text-muted-foreground">
                                                        {[
                                                            r.set,
                                                            r.number,
                                                            r.brand,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <div className="font-medium">
                                                {r.grader} {r.grade}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {r.state}
                                            </div>
                                        </td>

                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {formatMoney(r.graded)}
                                            <div
                                                className={cn(
                                                    'text-xs',
                                                    r.graded_confidence < THIN
                                                        ? 'text-amber-600 dark:text-amber-400'
                                                        : 'text-muted-foreground',
                                                )}
                                                title={`Confidence ${r.graded_confidence}`}
                                            >
                                                {r.graded_n} sold
                                            </div>
                                        </td>

                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {formatMoney(r.raw)}
                                            <div
                                                className={cn(
                                                    'text-xs',
                                                    r.raw_confidence < THIN
                                                        ? 'text-amber-600 dark:text-amber-400'
                                                        : 'text-muted-foreground',
                                                )}
                                                title={`Confidence ${r.raw_confidence}`}
                                            >
                                                {r.raw_n} sold
                                            </div>
                                        </td>

                                        <td className="px-3 py-2 text-right font-semibold text-red-600 tabular-nums dark:text-red-400">
                                            −{formatMoney(r.gap)}
                                        </td>

                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {r.ratio != null
                                                ? `${Math.round(r.ratio * 100)}%`
                                                : '—'}
                                        </td>

                                        <td className="px-2 py-2">
                                            <Button
                                                asChild
                                                variant="ghost"
                                                size="icon"
                                                className="size-7"
                                            >
                                                <Link
                                                    href={r.url}
                                                    aria-label={`Open ${r.name}`}
                                                >
                                                    <ExternalLink className="size-4" />
                                                </Link>
                                            </Button>
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
        </>
    );
}

AdminPriceInversions.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Price inversions', href: '/admin/price-inversions' },
    ],
};
