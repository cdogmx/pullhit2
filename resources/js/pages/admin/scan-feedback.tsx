import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { AdminPagination } from '@/types';

type Stat = {
    source: 'cache' | 'vision';
    total: number;
    correct: number;
    wrong: number;
    accuracy: number | null;
};

type Feedback = {
    id: number;
    source: 'cache' | 'vision';
    was_correct: boolean;
    user: string | null;
    identified: string;
    detected: string | null;
    corrected: string | null;
    created_at: string | null;
};

type Filters = { source: string; result: string };

type Props = {
    feedback: Feedback[];
    pagination: AdminPagination;
    filters: Filters;
    stats: Stat[];
};

const ALL = '__all__';

function formatDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '';
}

export default function AdminScanFeedback({
    feedback,
    pagination,
    filters,
    stats,
}: Props) {
    const apply = (changes: Record<string, string | number> = {}) =>
        router.get(
            '/admin/scan-feedback',
            { ...filters, page: 1, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const onFilter = (key: keyof Filters, value: string) =>
        apply({ [key]: value === ALL ? '' : value });

    return (
        <>
            <Head title="Admin · Scan feedback" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                {/* Accuracy by source */}
                <div className="grid gap-4 sm:grid-cols-2">
                    {stats.map((s) => (
                        <Card key={s.source}>
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs text-muted-foreground capitalize">
                                        {s.source === 'cache'
                                            ? 'Cache accuracy'
                                            : 'AI accuracy'}
                                    </p>
                                    <span className="text-xs text-muted-foreground">
                                        {s.total.toLocaleString()} reports
                                    </span>
                                </div>
                                <p className="mt-1 text-2xl font-bold tracking-tight">
                                    {s.accuracy === null ? '—' : `${s.accuracy}%`}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    <span className="text-emerald-600 dark:text-emerald-400">
                                        {s.correct.toLocaleString()} correct
                                    </span>{' '}
                                    ·{' '}
                                    <span className="text-red-600 dark:text-red-400">
                                        {s.wrong.toLocaleString()} wrong
                                    </span>
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={filters.source || ALL}
                        onValueChange={(v) => onFilter('source', v)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All sources" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All sources</SelectItem>
                            <SelectItem value="cache">Via cache</SelectItem>
                            <SelectItem value="vision">Via AI</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.result || ALL}
                        onValueChange={(v) => onFilter('result', v)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All results" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All results</SelectItem>
                            <SelectItem value="correct">Correct</SelectItem>
                            <SelectItem value="wrong">Wrong</SelectItem>
                        </SelectContent>
                    </Select>
                    {(filters.source || filters.result) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.get(
                                    '/admin/scan-feedback',
                                    {},
                                    { preserveScroll: true, replace: true },
                                )
                            }
                        >
                            Clear
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="overflow-x-auto pt-6">
                        <p className="mb-2 text-xs text-muted-foreground">
                            {pagination.total.toLocaleString()} reports
                        </p>
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="py-2 pr-3 font-medium">
                                        Date
                                    </th>
                                    <th className="py-2 pr-3 font-medium">
                                        Source
                                    </th>
                                    <th className="py-2 pr-3 font-medium">
                                        Result
                                    </th>
                                    <th className="py-2 pr-3 font-medium">
                                        Identified
                                    </th>
                                    <th className="py-2 pr-3 font-medium">
                                        Detected → Corrected
                                    </th>
                                    <th className="py-2 font-medium">User</th>
                                </tr>
                            </thead>
                            <tbody>
                                {feedback.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No feedback yet.
                                        </td>
                                    </tr>
                                )}
                                {feedback.map((f) => (
                                    <tr
                                        key={f.id}
                                        className="border-b border-border/60 last:border-0"
                                    >
                                        <td className="py-2 pr-3 whitespace-nowrap text-muted-foreground">
                                            {formatDate(f.created_at)}
                                        </td>
                                        <td className="py-2 pr-3">
                                            <Badge
                                                variant="secondary"
                                                className="text-[10px]"
                                            >
                                                {f.source === 'cache'
                                                    ? 'Cache'
                                                    : 'AI'}
                                            </Badge>
                                        </td>
                                        <td
                                            className={cn(
                                                'py-2 pr-3 font-medium',
                                                f.was_correct
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-red-600 dark:text-red-400',
                                            )}
                                        >
                                            {f.was_correct ? 'Correct' : 'Wrong'}
                                        </td>
                                        <td className="py-2 pr-3">
                                            {f.identified || '—'}
                                        </td>
                                        <td className="py-2 pr-3 text-muted-foreground">
                                            {f.detected ?? '—'}
                                            {f.corrected && (
                                                <>
                                                    {' → '}
                                                    <span className="text-foreground">
                                                        {f.corrected}
                                                    </span>
                                                </>
                                            )}
                                        </td>
                                        <td className="py-2 text-muted-foreground">
                                            {f.user ?? '—'}
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

AdminScanFeedback.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Scan feedback', href: '/admin/scan-feedback' },
    ],
};
