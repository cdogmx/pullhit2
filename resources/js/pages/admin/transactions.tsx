import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type {
    AdminPagination,
    AdminTransaction,
    AdminTransactionFilters,
    AdminTransactionTotals,
} from '@/types';

type Props = {
    transactions: AdminTransaction[];
    pagination: AdminPagination;
    filters: AdminTransactionFilters;
    totals: AdminTransactionTotals;
};

const ALL = '__all__';

const TYPES = [
    { value: 'subscription', label: 'Subscription' },
    { value: 'credits', label: 'Credits' },
    { value: 'refund', label: 'Refund' },
];

const STATUSES = [
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'failed', label: 'Failed' },
    { value: 'refunded', label: 'Refunded' },
];

const STATUS_STYLE: Record<string, string> = {
    succeeded: 'text-emerald-600 dark:text-emerald-400',
    failed: 'text-red-600 dark:text-red-400',
    refunded: 'text-amber-600 dark:text-amber-400',
};

function formatMoney(amount: number | null, currency: string | null): string {
    if (amount === null) {
        return '—';
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency || 'USD',
        }).format(amount / 100);
    } catch {
        return `$${(amount / 100).toFixed(2)}`;
    }
}

function formatDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString(undefined, {
              year: 'numeric',
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '';
}

export default function AdminTransactions({
    transactions,
    pagination,
    filters,
    totals,
}: Props) {
    const [q, setQ] = useState(filters.q);

    const apply = (changes: Record<string, string | number> = {}) =>
        router.get(
            '/admin/transactions',
            { ...filters, q, page: 1, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const onFilter = (key: keyof AdminTransactionFilters, value: string) =>
        apply({ [key]: value === ALL ? '' : value });

    const stats = [
        { label: 'Gross revenue', value: formatMoney(totals.gross, 'USD') },
        { label: 'Refunded', value: formatMoney(totals.refunded, 'USD') },
        { label: 'Transactions', value: totals.count.toLocaleString() },
    ];

    return (
        <>
            <Head title="Admin · Transactions" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                {/* Totals */}
                <div className="grid gap-4 sm:grid-cols-3">
                    {stats.map((s) => (
                        <Card key={s.label}>
                            <CardContent className="pt-6">
                                <p className="text-xs text-muted-foreground">
                                    {s.label}
                                </p>
                                <p className="mt-1 text-2xl font-bold tracking-tight">
                                    {s.value}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Filter bar */}
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        placeholder="Search user or payment id"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && apply()}
                        className="w-64"
                    />
                    <Select
                        value={filters.type || ALL}
                        onValueChange={(v) => onFilter('type', v)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All types</SelectItem>
                            {TYPES.map((t) => (
                                <SelectItem key={t.value} value={t.value}>
                                    {t.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.status || ALL}
                        onValueChange={(v) => onFilter('status', v)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All statuses</SelectItem>
                            {STATUSES.map((s) => (
                                <SelectItem key={s.value} value={s.value}>
                                    {s.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {(filters.q || filters.type || filters.status) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setQ('');
                                router.get(
                                    '/admin/transactions',
                                    {},
                                    { preserveScroll: true, replace: true },
                                );
                            }}
                        >
                            Clear
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="overflow-x-auto pt-6">
                        <p className="mb-2 text-xs text-muted-foreground">
                            {pagination.total.toLocaleString()} transactions
                        </p>
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="py-2 pr-3 font-medium">
                                        Date
                                    </th>
                                    <th className="py-2 pr-3 font-medium">
                                        User
                                    </th>
                                    <th className="py-2 pr-3 font-medium">
                                        Description
                                    </th>
                                    <th className="py-2 pr-3 font-medium">
                                        Type
                                    </th>
                                    <th className="py-2 pr-3 font-medium">
                                        Status
                                    </th>
                                    <th className="py-2 text-right font-medium">
                                        Amount
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {transactions.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No transactions found.
                                        </td>
                                    </tr>
                                )}
                                {transactions.map((t) => (
                                    <tr
                                        key={t.id}
                                        className="border-b border-border/60 last:border-0"
                                    >
                                        <td className="py-2 pr-3 whitespace-nowrap text-muted-foreground">
                                            {formatDate(t.created_at)}
                                        </td>
                                        <td className="py-2 pr-3">
                                            {t.user ? (
                                                <>
                                                    <p className="font-medium">
                                                        {t.user.name}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {t.user.email}
                                                    </p>
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    (deleted)
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 pr-3">
                                            {t.description ?? '—'}
                                            {t.dodo_payment_id && (
                                                <p className="text-xs text-muted-foreground">
                                                    {t.dodo_payment_id}
                                                </p>
                                            )}
                                        </td>
                                        <td className="py-2 pr-3">
                                            <Badge
                                                variant="secondary"
                                                className="text-[10px] capitalize"
                                            >
                                                {t.type}
                                            </Badge>
                                        </td>
                                        <td
                                            className={cn(
                                                'py-2 pr-3 capitalize',
                                                STATUS_STYLE[t.status] ??
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {t.status}
                                        </td>
                                        <td className="py-2 text-right font-medium tabular-nums">
                                            {formatMoney(t.amount, t.currency)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {pagination.last_page > 1 && (
                            <div className="mt-3 flex items-center justify-between text-sm">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={pagination.page <= 1}
                                    onClick={() =>
                                        apply({ page: pagination.page - 1 })
                                    }
                                >
                                    Previous
                                </Button>
                                <span className="text-muted-foreground">
                                    Page {pagination.page} of{' '}
                                    {pagination.last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        pagination.page >= pagination.last_page
                                    }
                                    onClick={() =>
                                        apply({ page: pagination.page + 1 })
                                    }
                                >
                                    Next
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AdminTransactions.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Transactions', href: '/admin/transactions' },
    ],
};
