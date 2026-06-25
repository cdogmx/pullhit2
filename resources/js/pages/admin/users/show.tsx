import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ExternalLink,
    Heart,
    Library,
    ShieldCheck,
    User as UserIcon,
} from 'lucide-react';
import { useState } from 'react';
import { ManageUserDialog } from '@/components/admin/manage-user-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useInitials } from '@/hooks/use-initials';
import type {
    AdminUserDetail,
    AdminUserLinks,
    AdminUserScan,
    AdminUserSession,
    AdminUserStats,
} from '@/types';

type Transaction = {
    id: number;
    type: string;
    status: string;
    description: string | null;
    amount: number | null;
    currency: string | null;
    created_at: string | null;
};

type Props = {
    user: AdminUserDetail;
    links: AdminUserLinks;
    stats: AdminUserStats;
    sessions: AdminUserSession[];
    scans: AdminUserScan[];
    transactions: Transaction[];
    tiers: { value: string; label: string }[];
};

function money(cents: number | null, currency = 'USD'): string {
    if (cents == null) {
        return '—';
    }

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency || 'USD',
    }).format(cents / 100);
}

function when(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}

/** A coarse "Browser on OS" label from a raw user-agent string. */
function device(ua: string | null): string {
    if (!ua) {
        return 'Unknown device';
    }

    const browser = /Edg/.test(ua)
        ? 'Edge'
        : /OPR|Opera/.test(ua)
          ? 'Opera'
          : /Chrome/.test(ua)
            ? 'Chrome'
            : /Firefox/.test(ua)
              ? 'Firefox'
              : /Safari/.test(ua)
                ? 'Safari'
                : 'Browser';

    const os = /Windows/.test(ua)
        ? 'Windows'
        : /iPhone|iPad|iOS/.test(ua)
          ? 'iOS'
          : /Mac OS X|Macintosh/.test(ua)
            ? 'macOS'
            : /Android/.test(ua)
              ? 'Android'
              : /Linux/.test(ua)
                ? 'Linux'
                : 'Unknown OS';

    return `${browser} · ${os}`;
}

type NumericStat = Exclude<keyof AdminUserStats, 'level'>;

const STAT_LABELS: { key: NumericStat; label: string }[] = [
    { key: 'collection_items', label: 'Collection cards' },
    { key: 'collections', label: 'Collections' },
    { key: 'wishlist_items', label: 'Wishlist cards' },
    { key: 'wishlists', label: 'Wishlists' },
    { key: 'followers', label: 'Followers' },
    { key: 'following', label: 'Following' },
    { key: 'scans', label: 'Scans' },
    { key: 'contributions', label: 'Contributions' },
    { key: 'card_reports', label: 'Card reports' },
    { key: 'contribution_points', label: 'Points' },
    { key: 'monthly_entries', label: 'Entries (mo.)' },
];

export default function AdminUserShow({
    user,
    links,
    stats,
    sessions,
    scans,
    transactions,
    tiers,
}: Props) {
    const getInitials = useInitials();
    const [managing, setManaging] = useState(false);

    return (
        <>
            <Head title={`Admin · ${user.name}`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <Link
                    href="/admin/users"
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Back to users
                </Link>

                {/* Identity header */}
                <Card>
                    <CardContent className="flex flex-wrap items-start gap-4 pt-6">
                        <Avatar className="size-16">
                            <AvatarImage
                                src={user.avatar ?? undefined}
                                alt={user.name}
                            />
                            <AvatarFallback className="bg-primary/15 text-lg font-bold text-primary">
                                {getInitials(user.name)}
                            </AvatarFallback>
                        </Avatar>

                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-bold tracking-tight">
                                    {user.name}
                                </h1>
                                {user.is_admin && <Badge>Admin</Badge>}
                                {user.banned_at && (
                                    <Badge variant="destructive">Banned</Badge>
                                )}
                                {user.cancel_scheduled && (
                                    <Badge variant="outline">Cancelling</Badge>
                                )}
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {user.email}
                                {user.email_verified_at ? (
                                    <span className="ml-1 inline-flex items-center gap-0.5 text-emerald-600 dark:text-emerald-400">
                                        <ShieldCheck className="size-3.5" />
                                        verified
                                    </span>
                                ) : (
                                    <span className="ml-1 text-amber-600 dark:text-amber-400">
                                        unverified
                                    </span>
                                )}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                {user.username
                                    ? `@${user.username}`
                                    : 'no username'}{' '}
                                · {user.tier}
                                {stats.level ? ` · ${stats.level}` : ''} ·{' '}
                                {user.provider
                                    ? `via ${user.provider}`
                                    : 'password'}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Joined {when(user.created_at)} · Last seen{' '}
                                {when(user.last_seen_at)}
                            </p>
                        </div>

                        <div className="flex flex-col items-end gap-2">
                            <Button size="sm" onClick={() => setManaging(true)}>
                                Manage
                            </Button>
                            <p className="text-right text-xs text-muted-foreground">
                                {user.credits.toLocaleString()} credits ·{' '}
                                {money(user.lifetime_amount)} lifetime
                            </p>
                        </div>
                    </CardContent>
                </Card>

                {/* Public surfaces */}
                {links && (
                    <div className="flex flex-wrap gap-2">
                        <LinkChip
                            href={links.profile}
                            icon={<UserIcon className="size-4" />}
                            label="Public profile"
                        />
                        <LinkChip
                            href={links.collection}
                            icon={<Library className="size-4" />}
                            label="Collection"
                        />
                        <LinkChip
                            href={links.wishlist}
                            icon={<Heart className="size-4" />}
                            label="Wishlist"
                        />
                    </div>
                )}

                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {STAT_LABELS.map(({ key, label }) => (
                        <div
                            key={key}
                            className="rounded-lg border border-border bg-card p-3"
                        >
                            <p className="text-lg font-semibold tabular-nums">
                                {stats[key].toLocaleString()}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {label}
                            </p>
                        </div>
                    ))}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Sessions / IPs */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recent sessions
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {sessions.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No active sessions on record.
                                </p>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead className="text-left text-xs text-muted-foreground">
                                        <tr className="border-b border-border">
                                            <th className="py-1.5 pr-3 font-medium">
                                                IP
                                            </th>
                                            <th className="py-1.5 pr-3 font-medium">
                                                Device
                                            </th>
                                            <th className="py-1.5 font-medium">
                                                Last active
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {sessions.map((s, i) => (
                                            <tr
                                                key={i}
                                                className="border-b border-border/60 last:border-0"
                                            >
                                                <td className="py-1.5 pr-3 font-mono text-xs">
                                                    {s.ip_address ?? '—'}
                                                </td>
                                                <td
                                                    className="py-1.5 pr-3 text-muted-foreground"
                                                    title={
                                                        s.user_agent ?? undefined
                                                    }
                                                >
                                                    {device(s.user_agent)}
                                                </td>
                                                <td className="py-1.5 text-xs text-muted-foreground">
                                                    {when(s.last_activity)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>

                    {/* Billing */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recent transactions
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {transactions.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No billing history.
                                </p>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead className="text-left text-xs text-muted-foreground">
                                        <tr className="border-b border-border">
                                            <th className="py-1.5 pr-3 font-medium">
                                                When
                                            </th>
                                            <th className="py-1.5 pr-3 font-medium">
                                                Type
                                            </th>
                                            <th className="py-1.5 pr-3 text-right font-medium">
                                                Amount
                                            </th>
                                            <th className="py-1.5 font-medium">
                                                Status
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {transactions.map((t) => (
                                            <tr
                                                key={t.id}
                                                className="border-b border-border/60 last:border-0"
                                            >
                                                <td className="py-1.5 pr-3 text-xs text-muted-foreground">
                                                    {when(t.created_at)}
                                                </td>
                                                <td className="py-1.5 pr-3 capitalize">
                                                    {t.type}
                                                </td>
                                                <td className="py-1.5 pr-3 text-right tabular-nums">
                                                    {money(
                                                        t.amount,
                                                        t.currency ?? 'USD',
                                                    )}
                                                </td>
                                                <td className="py-1.5 text-xs capitalize text-muted-foreground">
                                                    {t.status}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Scans */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Scan history
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {scans.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                This user hasn't scanned any cards.
                            </p>
                        ) : (
                            <div className="space-y-4">
                                {scans.map((scan) => (
                                    <div
                                        key={scan.id}
                                        className="flex gap-4 rounded-lg border border-border p-3"
                                    >
                                        <div className="w-24 shrink-0">
                                            {scan.image_url ? (
                                                <img
                                                    src={scan.image_url}
                                                    alt="Scan"
                                                    loading="lazy"
                                                    className="aspect-[3/4] w-full rounded-md border border-border object-cover"
                                                />
                                            ) : (
                                                <div className="flex aspect-[3/4] w-full items-center justify-center rounded-md border border-dashed border-border text-[10px] text-muted-foreground">
                                                    No image
                                                </div>
                                            )}
                                            <p className="mt-1 text-center text-[10px] text-muted-foreground">
                                                {when(scan.created_at)}
                                            </p>
                                        </div>

                                        <div className="min-w-0 flex-1">
                                            <p className="text-xs text-muted-foreground">
                                                <span className="capitalize">
                                                    {scan.mode}
                                                </span>{' '}
                                                · {scan.card_count} cards ·{' '}
                                                {scan.ai_reads} AI ·{' '}
                                                {scan.cache_hits} cached
                                            </p>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                {scan.results.length === 0 ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        No detections recorded.
                                                    </span>
                                                ) : (
                                                    scan.results.map((r, i) => {
                                                        const label =
                                                            r.match?.name ??
                                                            r.name ??
                                                            'Unknown';
                                                        const sub =
                                                            r.match?.set ??
                                                            r.number ??
                                                            null;
                                                        const inner = (
                                                            <>
                                                                {r.match
                                                                    ?.image_url && (
                                                                    <img
                                                                        src={
                                                                            r
                                                                                .match
                                                                                .image_url
                                                                        }
                                                                        alt=""
                                                                        loading="lazy"
                                                                        className="size-8 rounded object-cover"
                                                                    />
                                                                )}
                                                                <span className="min-w-0">
                                                                    <span className="block truncate text-xs font-medium">
                                                                        {label}
                                                                    </span>
                                                                    {sub && (
                                                                        <span className="block truncate text-[10px] text-muted-foreground">
                                                                            {sub}
                                                                        </span>
                                                                    )}
                                                                </span>
                                                            </>
                                                        );

                                                        return r.match?.url ? (
                                                            <a
                                                                key={i}
                                                                href={
                                                                    r.match.url
                                                                }
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="flex max-w-48 items-center gap-2 rounded-md border border-border px-2 py-1 transition-colors hover:border-ring"
                                                            >
                                                                {inner}
                                                            </a>
                                                        ) : (
                                                            <span
                                                                key={i}
                                                                className="flex max-w-48 items-center gap-2 rounded-md border border-dashed border-border px-2 py-1"
                                                            >
                                                                {inner}
                                                            </span>
                                                        );
                                                    })
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <ManageUserDialog
                user={managing ? user : null}
                tiers={tiers}
                open={managing}
                onOpenChange={setManaging}
            />
        </>
    );
}

function LinkChip({
    href,
    icon,
    label,
}: {
    href: string;
    icon: React.ReactNode;
    label: string;
}) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-1.5 text-sm transition-colors hover:border-ring"
        >
            {icon}
            {label}
            <ExternalLink className="size-3 text-muted-foreground" />
        </a>
    );
}

AdminUserShow.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Users', href: '/admin/users' },
        { title: 'Detail', href: '#' },
    ],
};
