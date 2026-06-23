import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { AdminPagination } from '@/types';

type Applied = {
    id: number;
    card: string;
    card_id: number | null;
    grade: string | null;
    price: number | null;
    title: string | null;
    search: string | null;
    observed_at: string | null;
};

type Miss = {
    id: number;
    title: string;
    reason: string;
    number: string | null;
    price: number | null;
    best: string | null;
    best_id: number | null;
    score: number | null;
    created_at: string | null;
};

type Props = {
    misses: Miss[];
    pagination: AdminPagination;
    counts: Record<string, number>;
    reason: string;
    applied: Applied[];
    appliedTotal: number;
};

const REASONS = [
    'no_number',
    'unmatched',
    'ambiguous',
    'low_score',
    'classify_rejected',
];

const REASON_STYLE: Record<string, string> = {
    no_number: 'text-muted-foreground',
    unmatched: 'text-muted-foreground',
    ambiguous: 'text-amber-600 dark:text-amber-400',
    low_score: 'text-amber-600 dark:text-amber-400',
    classify_rejected: 'text-red-600 dark:text-red-400',
};

const money = (cents: number | null) =>
    cents == null ? '—' : '$' + (cents / 100).toLocaleString(undefined, { maximumFractionDigits: 0 });

export default function AdminEbaySweep({
    misses,
    pagination,
    counts,
    reason,
    applied,
    appliedTotal,
}: Props) {
    const totalMisses = Object.values(counts).reduce((a, b) => a + b, 0);

    return (
        <>
            <Head title="Admin · eBay sweep" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                {/* Reason summary */}
                <div className="flex flex-wrap gap-2">
                    <Badge variant="secondary" className="text-xs">
                        {appliedTotal.toLocaleString()} applied
                    </Badge>
                    <Badge variant="secondary" className="text-xs">
                        {totalMisses.toLocaleString()} misses
                    </Badge>
                    {REASONS.map((r) => (
                        <Badge
                            key={r}
                            variant="outline"
                            className={cn('text-xs', REASON_STYLE[r])}
                        >
                            {r.replace('_', ' ')}: {counts[r] ?? 0}
                        </Badge>
                    ))}
                </div>

                {/* Recently applied matches */}
                <div>
                    <h2 className="mb-2 text-sm font-semibold">
                        Recently applied ({applied.length})
                    </h2>
                    <div className="overflow-hidden rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <tbody>
                                {applied.length === 0 && (
                                    <tr>
                                        <td className="px-3 py-6 text-center text-muted-foreground">
                                            No sweep sales applied yet.
                                        </td>
                                    </tr>
                                )}
                                {applied.map((a) => (
                                    <tr
                                        key={a.id}
                                        className="border-b border-border/60 last:border-0"
                                    >
                                        <td className="px-3 py-2">
                                            <div className="truncate text-xs text-muted-foreground">
                                                {a.title}
                                            </div>
                                            <div className="font-medium">
                                                {a.card_id ? (
                                                    <Link
                                                        href={`/admin/cards/${a.card_id}`}
                                                        className="hover:underline"
                                                    >
                                                        {a.card}
                                                    </Link>
                                                ) : (
                                                    a.card
                                                )}
                                                {a.grade && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="ml-2 text-[10px]"
                                                    >
                                                        {a.grade}
                                                    </Badge>
                                                )}
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">
                                            {money(a.price)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Misses */}
                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-sm font-semibold">
                            Unmatched ({pagination.total.toLocaleString()})
                        </h2>
                        <Select
                            value={reason}
                            onValueChange={(v) =>
                                router.get(
                                    '/admin/ebay-sweep',
                                    { reason: v },
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All reasons</SelectItem>
                                {REASONS.map((r) => (
                                    <SelectItem key={r} value={r}>
                                        {r.replace('_', ' ')}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="overflow-hidden rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <tbody>
                                {misses.length === 0 && (
                                    <tr>
                                        <td className="px-3 py-6 text-center text-muted-foreground">
                                            Nothing here.
                                        </td>
                                    </tr>
                                )}
                                {misses.map((m) => (
                                    <tr
                                        key={m.id}
                                        className="border-b border-border/60 last:border-0"
                                    >
                                        <td className="px-3 py-2">
                                            <div className="truncate">
                                                {m.title}
                                            </div>
                                            <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                                <span
                                                    className={cn(
                                                        'font-medium capitalize',
                                                        REASON_STYLE[m.reason],
                                                    )}
                                                >
                                                    {m.reason.replace('_', ' ')}
                                                </span>
                                                {m.number && (
                                                    <span>#{m.number}</span>
                                                )}
                                                {m.best && (
                                                    <span>
                                                        ≈{' '}
                                                        {m.best_id ? (
                                                            <Link
                                                                href={`/admin/cards/${m.best_id}`}
                                                                className="hover:underline"
                                                            >
                                                                {m.best}
                                                            </Link>
                                                        ) : (
                                                            m.best
                                                        )}
                                                        {m.score != null &&
                                                            ` (${m.score})`}
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums text-muted-foreground">
                                            {money(m.price)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {pagination.last_page > 1 && (
                        <div className="mt-3 flex items-center justify-between text-sm">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pagination.page <= 1}
                                onClick={() =>
                                    router.get(
                                        '/admin/ebay-sweep',
                                        { reason, page: pagination.page - 1 },
                                        { preserveScroll: true },
                                    )
                                }
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
                                onClick={() =>
                                    router.get(
                                        '/admin/ebay-sweep',
                                        { reason, page: pagination.page + 1 },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Next
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

AdminEbaySweep.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'eBay sweep', href: '/admin/ebay-sweep' },
    ],
};
