import { ArrowDown, ArrowUp, ChevronsUpDown, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export type SortDir = 'asc' | 'desc';

export type DataTableColumn<T> = {
    /** Stable column id (used as the sort key + React key). */
    key: string;
    header: string;
    align?: 'left' | 'right';
    sortable?: boolean;
    headerClassName?: string;
    cellClassName?: string;
    /**
     * The scalar used for sorting + global search. Numbers sort numerically;
     * strings sort with locale + numeric awareness. Columns without a `value`
     * are display-only (e.g. an actions column).
     */
    value?: (row: T) => string | number | null | undefined;
    /** Rendered cell. Defaults to the stringified `value`. */
    cell?: (row: T) => ReactNode;
};

/**
 * A lightweight client-side table with global search + click-to-sort headers,
 * built on the shadcn primitives (no table library). For fully-loaded datasets
 * (brands, sets); server-side lists (cards) drive {@link SortHeader} directly.
 */
export function DataTable<T>({
    data,
    columns,
    getRowKey,
    searchPlaceholder = 'Search…',
    initialSort,
    toolbar,
    emptyText = 'No results.',
}: {
    data: T[];
    columns: DataTableColumn<T>[];
    getRowKey: (row: T) => string | number;
    searchPlaceholder?: string;
    initialSort?: { key: string; dir: SortDir };
    /** Extra filter controls rendered in the toolbar, after the search box. */
    toolbar?: ReactNode;
    emptyText?: string;
}) {
    const [q, setQ] = useState('');
    const [sort, setSort] = useState<{ key: string; dir: SortDir } | null>(
        initialSort ?? null,
    );

    const searchable = useMemo(() => columns.filter((c) => c.value), [columns]);

    const rows = useMemo(() => {
        const needle = q.trim().toLowerCase();

        let out = needle
            ? data.filter((row) =>
                  searchable.some((c) => {
                      const v = c.value?.(row);

                      return (
                          v != null && String(v).toLowerCase().includes(needle)
                      );
                  }),
              )
            : data;

        if (sort) {
            const col = columns.find((c) => c.key === sort.key);

            if (col?.value) {
                out = [...out].sort((a, b) => {
                    const cmp = compareValues(col.value!(a), col.value!(b));

                    return sort.dir === 'asc' ? cmp : -cmp;
                });
            }
        }

        return out;
    }, [data, q, sort, columns, searchable]);

    const toggleSort = (key: string) =>
        setSort((s) => {
            if (s?.key !== key) {
                return { key, dir: 'asc' };
            }

            return s.dir === 'asc' ? { key, dir: 'desc' } : null;
        });

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative w-56">
                    <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={searchPlaceholder}
                        className="pl-8"
                    />
                </div>
                {toolbar}
                <span className="ml-auto text-xs text-muted-foreground">
                    {rows.length.toLocaleString()} of{' '}
                    {data.length.toLocaleString()}
                </span>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-muted-foreground">
                        <tr className="border-b border-border">
                            {columns.map((c) => (
                                <th
                                    key={c.key}
                                    className={cn(
                                        'py-2 pr-3 font-medium',
                                        c.align === 'right' && 'text-right',
                                        c.headerClassName,
                                    )}
                                >
                                    {c.sortable && c.value ? (
                                        <SortHeader
                                            label={c.header}
                                            align={c.align}
                                            active={sort?.key === c.key}
                                            dir={
                                                sort?.key === c.key
                                                    ? sort.dir
                                                    : null
                                            }
                                            onClick={() => toggleSort(c.key)}
                                        />
                                    ) : (
                                        c.header
                                    )}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={columns.length}
                                    className="py-6 text-center text-muted-foreground"
                                >
                                    {emptyText}
                                </td>
                            </tr>
                        ) : (
                            rows.map((row) => (
                                <tr
                                    key={getRowKey(row)}
                                    className="border-b border-border/60 last:border-0"
                                >
                                    {columns.map((c) => (
                                        <td
                                            key={c.key}
                                            className={cn(
                                                'py-2 pr-3',
                                                c.align === 'right' &&
                                                    'text-right',
                                                c.cellClassName,
                                            )}
                                        >
                                            {c.cell
                                                ? c.cell(row)
                                                : String(c.value?.(row) ?? '')}
                                        </td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/** asc-style comparator: nulls first, numbers numeric, strings locale+numeric. */
function compareValues(
    a: string | number | null | undefined,
    b: string | number | null | undefined,
): number {
    if (a == null && b == null) {
        return 0;
    }

    if (a == null) {
        return -1;
    }

    if (b == null) {
        return 1;
    }

    if (typeof a === 'number' && typeof b === 'number') {
        return a - b;
    }

    return String(a).localeCompare(String(b), undefined, { numeric: true });
}

/**
 * A clickable, sortable column header. Used internally by {@link DataTable} and
 * directly by server-sorted lists (where `dir` reflects the fixed server order).
 */
export function SortHeader({
    label,
    active,
    dir,
    align,
    onClick,
}: {
    label: string;
    active: boolean;
    dir: SortDir | null;
    align?: 'left' | 'right';
    onClick: () => void;
}) {
    const Icon = !active
        ? ChevronsUpDown
        : dir === 'desc'
          ? ArrowDown
          : ArrowUp;

    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1 font-medium hover:text-foreground',
                active && 'text-foreground',
                align === 'right' && 'flex-row-reverse',
            )}
        >
            {label}
            <Icon className="size-3 opacity-70" />
        </button>
    );
}
