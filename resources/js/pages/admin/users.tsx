import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ManageUserDialog } from '@/components/admin/manage-user-dialog';
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
import type { AdminPagination, AdminUser, AdminUserFilters } from '@/types';

type Props = {
    users: AdminUser[];
    pagination: AdminPagination;
    filters: AdminUserFilters;
    tiers: { value: string; label: string }[];
};

const ALL = '__all__';

const ROLES = [
    { value: 'admin', label: 'Admins' },
    { value: 'banned', label: 'Banned' },
];

const SORTS = [
    { value: 'recent', label: 'Newest' },
    { value: 'name', label: 'Name' },
    { value: 'spend', label: 'Top spend' },
];

function formatMoney(cents: number): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
    }).format(cents / 100);
}

export default function AdminUsers({
    users,
    pagination,
    filters,
    tiers,
}: Props) {
    const [q, setQ] = useState(filters.q);
    const [managing, setManaging] = useState<AdminUser | null>(null);

    const apply = (changes: Record<string, string | number> = {}) =>
        router.get(
            '/admin/users',
            { ...filters, q, page: 1, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const onFilter = (key: keyof AdminUserFilters, value: string) =>
        apply({ [key]: value === ALL ? '' : value });

    return (
        <>
            <Head title="Admin · Users" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                {/* Filter bar */}
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        placeholder="Search name, email, username"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && apply()}
                        className="w-64"
                    />
                    <Select
                        value={filters.tier || ALL}
                        onValueChange={(v) => onFilter('tier', v)}
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="All tiers" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All tiers</SelectItem>
                            {tiers.map((t) => (
                                <SelectItem key={t.value} value={t.value}>
                                    {t.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.role || ALL}
                        onValueChange={(v) => onFilter('role', v)}
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Everyone" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Everyone</SelectItem>
                            {ROLES.map((r) => (
                                <SelectItem key={r.value} value={r.value}>
                                    {r.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.sort}
                        onValueChange={(v) => onFilter('sort', v)}
                    >
                        <SelectTrigger className="w-36">
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
                    {(filters.q || filters.tier || filters.role) && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setQ('');
                                router.get(
                                    '/admin/users',
                                    { sort: filters.sort },
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
                            {pagination.total.toLocaleString()} users
                        </p>
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="py-2 pr-3 font-medium">
                                        User
                                    </th>
                                    <th className="py-2 pr-3 font-medium">
                                        Tier
                                    </th>
                                    <th className="py-2 pr-3 text-right font-medium">
                                        Credits
                                    </th>
                                    <th className="py-2 pr-3 text-right font-medium">
                                        Lifetime
                                    </th>
                                    <th className="py-2 pr-3 font-medium">
                                        Status
                                    </th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((u) => (
                                    <tr
                                        key={u.id}
                                        className="border-b border-border/60 last:border-0"
                                    >
                                        <td className="py-2 pr-3">
                                            <Link
                                                href={`/admin/users/${u.id}`}
                                                className="font-medium hover:underline"
                                            >
                                                {u.name}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {u.email}
                                            </p>
                                        </td>
                                        <td className="py-2 pr-3 capitalize">
                                            {u.tier}
                                        </td>
                                        <td className="py-2 pr-3 text-right tabular-nums text-muted-foreground">
                                            {u.credits.toLocaleString()}
                                        </td>
                                        <td className="py-2 pr-3 text-right tabular-nums text-muted-foreground">
                                            {u.lifetime_amount
                                                ? formatMoney(u.lifetime_amount)
                                                : '—'}
                                        </td>
                                        <td className="py-2 pr-3">
                                            <div className="flex flex-wrap gap-1">
                                                {u.is_admin && (
                                                    <Badge className="text-[10px]">
                                                        Admin
                                                    </Badge>
                                                )}
                                                {u.banned_at && (
                                                    <Badge
                                                        variant="destructive"
                                                        className="text-[10px]"
                                                    >
                                                        Banned
                                                    </Badge>
                                                )}
                                                {u.cancel_scheduled && (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px]"
                                                    >
                                                        Cancelling
                                                    </Badge>
                                                )}
                                            </div>
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setManaging(u)}
                                            >
                                                Manage
                                            </Button>
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

            <ManageUserDialog
                user={managing}
                tiers={tiers}
                open={managing !== null}
                onOpenChange={(o) => !o && setManaging(null)}
            />
        </>
    );
}

AdminUsers.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Users', href: '/admin/users' },
    ],
};
