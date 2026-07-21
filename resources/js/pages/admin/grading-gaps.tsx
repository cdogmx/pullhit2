import { Head, Link, router } from '@inertiajs/react';
import { ArrowDownUp, ExternalLink, ImageOff } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
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
    image_url: string | null;
    url: string;
    /** Money fields are integer minor units (cents). */
    nm: number;
    nm_n: number;
    nm_confidence: number;
    psa10: number;
    psa10_n: number;
    psa10_confidence: number;
    delta: number;
    multiple: number | null;
    profit: number;
};

type Filters = {
    fee: number;
    min_value: number;
    min_graded_sales: number;
    brand: string;
    set: string;
    year: number | null;
    sort: 'profit' | 'multiple';
};

type Option = { value: string; label: string };

type Options = { brands: Option[]; years: number[]; sets: Option[] };

type Props = {
    rows: Row[];
    pagination: AdminPagination;
    filters: Filters;
    options: Options;
};

const ALL = '__all__';

export default function AdminGradingGaps({
    rows,
    pagination,
    filters,
    options,
}: Props) {
    const [fee, setFee] = useState(String(filters.fee));
    const [minValue, setMinValue] = useState(String(filters.min_value));

    const apply = (changes: Record<string, string | number | null> = {}) =>
        router.get(
            '/admin/grading-gaps',
            { ...filters, year: filters.year ?? '', page: 1, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Admin · Grading gaps" />
            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-bold tracking-tight">
                        Grading gaps
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Cards worth more in a PSA 10 slab than raw. Profit ={' '}
                        <span className="font-medium text-foreground">
                            PSA 10 value − (Near Mint price + grading fee)
                        </span>
                        . Real sold data only.
                    </p>
                </div>

                {/* Controls */}
                <div className="flex flex-wrap items-end gap-3 rounded-xl border border-border p-3">
                    <div className="grid gap-1">
                        <Label className="text-xs">Brand</Label>
                        <Select
                            value={filters.brand || ALL}
                            onValueChange={(v) =>
                                // Sets are scoped to the brand, so reset the set too.
                                apply({ brand: v === ALL ? '' : v, set: '' })
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
                        <Label className="text-xs">Set</Label>
                        <Combobox
                            options={[
                                { value: '', label: 'All sets' },
                                ...options.sets,
                            ]}
                            value={filters.set}
                            onChange={(v) => apply({ set: v })}
                            placeholder="All sets"
                            searchPlaceholder="Search sets…"
                            triggerClassName="h-9 w-56"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Year</Label>
                        <Select
                            value={filters.year ? String(filters.year) : ALL}
                            onValueChange={(v) =>
                                apply({ year: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="h-9 w-28">
                                <SelectValue placeholder="Any" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Any year</SelectItem>
                                {options.years.map((y) => (
                                    <SelectItem key={y} value={String(y)}>
                                        {y}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="fee" className="text-xs">
                            Grading fee ($)
                        </Label>
                        <Input
                            id="fee"
                            value={fee}
                            onChange={(e) => setFee(e.target.value)}
                            onBlur={() => apply({ fee: fee || '0' })}
                            onKeyDown={(e) =>
                                e.key === 'Enter' && apply({ fee: fee || '0' })
                            }
                            inputMode="decimal"
                            className="h-9 w-24"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="min_value" className="text-xs">
                            Min NM value ($)
                        </Label>
                        <Input
                            id="min_value"
                            value={minValue}
                            onChange={(e) => setMinValue(e.target.value)}
                            onBlur={() =>
                                apply({ min_value: minValue || '0' })
                            }
                            onKeyDown={(e) =>
                                e.key === 'Enter' &&
                                apply({ min_value: minValue || '0' })
                            }
                            inputMode="decimal"
                            className="h-9 w-28"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="min_graded" className="text-xs">
                            Min PSA 10 sales
                        </Label>
                        <Select
                            value={String(filters.min_graded_sales)}
                            onValueChange={(v) =>
                                apply({ min_graded_sales: v })
                            }
                        >
                            <SelectTrigger id="min_graded" className="h-9 w-24">
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
                        <Label htmlFor="sort" className="text-xs">
                            Sort by
                        </Label>
                        <Select
                            value={filters.sort}
                            onValueChange={(v) => apply({ sort: v })}
                        >
                            <SelectTrigger id="sort" className="h-9 w-36">
                                <ArrowDownUp className="size-3.5" />
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="profit">
                                    Profit (net)
                                </SelectItem>
                                <SelectItem value="multiple">
                                    Multiple (×)
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="ml-auto self-center text-sm text-muted-foreground">
                        {pagination.total.toLocaleString()} card
                        {pagination.total === 1 ? '' : 's'}
                    </div>
                </div>

                {/* Table */}
                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">Card</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Near Mint
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    PSA 10
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    ×
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Profit
                                </th>
                                <th className="w-8" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border/60">
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-3 py-10 text-center text-muted-foreground"
                                    >
                                        No cards clear the grading fee with these
                                        filters.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((r) => (
                                    <tr
                                        key={r.id}
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
                                                        {[r.set, r.number]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {formatMoney(r.nm)}
                                            <div className="text-xs text-muted-foreground">
                                                {r.nm_n} sold
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {formatMoney(r.psa10)}
                                            <div className="text-xs text-muted-foreground">
                                                {r.psa10_n} sold
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 text-right font-medium tabular-nums">
                                            {r.multiple != null
                                                ? `${r.multiple}×`
                                                : '—'}
                                        </td>
                                        <td
                                            className={cn(
                                                'px-3 py-2 text-right font-semibold tabular-nums',
                                                r.profit > 0
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {r.profit > 0 ? '+' : ''}
                                            {formatMoney(r.profit)}
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
                                                    aria-label="Open card"
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

AdminGradingGaps.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Grading gaps', href: '/admin/grading-gaps' },
    ],
};
