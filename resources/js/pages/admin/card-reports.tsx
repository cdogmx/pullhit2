import { Head, router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { toast } from 'sonner';
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

type Report = {
    id: number;
    kind: 'card' | 'set';
    name: string;
    details: Record<string, string> | null;
    status: 'pending' | 'approved' | 'rejected';
    user: string | null;
    created_at: string | null;
};

type Props = {
    reports: Report[];
    pagination: AdminPagination;
    filters: { status: string };
    pending: number;
};

const STATUS_STYLE: Record<string, string> = {
    pending: 'text-amber-600 dark:text-amber-400',
    approved: 'text-emerald-600 dark:text-emerald-400',
    rejected: 'text-red-600 dark:text-red-400',
};

const DETAIL_ORDER = ['number', 'set', 'brand', 'language', 'source_url', 'notes'];

export default function AdminCardReports({
    reports,
    pagination,
    filters,
    pending,
}: Props) {
    const act = (id: number, action: 'approve' | 'reject') =>
        router.post(
            `/admin/card-reports/${id}/${action}`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        action === 'approve'
                            ? 'Accepted — points awarded.'
                            : 'Report rejected.',
                    ),
            },
        );

    return (
        <>
            <Head title="Admin · Card reports" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-muted-foreground">
                        {pending.toLocaleString()} pending report
                        {pending === 1 ? '' : 's'}.
                    </p>
                    <Select
                        value={filters.status || 'pending'}
                        onValueChange={(v) =>
                            router.get(
                                '/admin/card-reports',
                                { status: v },
                                { preserveState: true, replace: true },
                            )
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="rejected">Rejected</SelectItem>
                            <SelectItem value="all">All</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {reports.length === 0 && (
                    <Card>
                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                            Nothing to review. 🎉
                        </CardContent>
                    </Card>
                )}

                {reports.map((r) => (
                    <Card key={r.id}>
                        <CardContent className="flex flex-col gap-3 pt-6 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">{r.name}</span>
                                    <Badge
                                        variant="secondary"
                                        className="text-[10px] capitalize"
                                    >
                                        Missing {r.kind}
                                    </Badge>
                                    <span
                                        className={cn(
                                            'text-xs font-medium capitalize',
                                            STATUS_STYLE[r.status],
                                        )}
                                    >
                                        {r.status}
                                    </span>
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    by {r.user ?? 'someone'}
                                </p>
                                {r.details && (
                                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                        {DETAIL_ORDER.filter(
                                            (k) => r.details?.[k],
                                        ).map((k) => (
                                            <span key={k}>
                                                <span className="capitalize">
                                                    {k.replace('_', ' ')}:
                                                </span>{' '}
                                                {k === 'source_url' ? (
                                                    <a
                                                        href={r.details![k]}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-primary hover:underline"
                                                    >
                                                        link
                                                    </a>
                                                ) : (
                                                    <span className="text-foreground">
                                                        {r.details![k]}
                                                    </span>
                                                )}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {r.status === 'pending' && (
                                <div className="flex shrink-0 gap-2">
                                    <Button
                                        size="sm"
                                        onClick={() => act(r.id, 'approve')}
                                    >
                                        <Check className="size-4" /> Accept
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => act(r.id, 'reject')}
                                    >
                                        <X className="size-4" /> Reject
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ))}

                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pagination.page <= 1}
                            onClick={() =>
                                router.get(
                                    '/admin/card-reports',
                                    {
                                        ...filters,
                                        page: pagination.page - 1,
                                    },
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
                                    '/admin/card-reports',
                                    {
                                        ...filters,
                                        page: pagination.page + 1,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

AdminCardReports.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Card reports', href: '/admin/card-reports' },
    ],
};
