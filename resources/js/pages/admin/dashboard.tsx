import { Head, Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { AdminStats, SetHealth } from '@/types';

type Props = { stats: AdminStats; health: SetHealth[] };

const CARDS: { key: keyof AdminStats; label: string }[] = [
    { key: 'sets', label: 'Sets' },
    { key: 'items', label: 'Catalog items' },
    { key: 'valued', label: 'Valued items' },
    { key: 'images', label: 'Images stored' },
    { key: 'users', label: 'Users' },
    { key: 'premium', label: 'Premium' },
    { key: 'admins', label: 'Admins' },
];

function pct(part: number, total: number): number {
    return total === 0 ? 0 : Math.round((part / total) * 100);
}

/** Green at full coverage, amber for partial, red for none. */
function coverageColor(value: number, total: number): string {
    if (total === 0) {
return 'text-muted-foreground';
}

    const p = pct(value, total);

    if (p >= 99) {
return 'text-emerald-600 dark:text-emerald-400';
}

    if (p >= 1) {
return 'text-amber-600 dark:text-amber-400';
}

    return 'text-red-600 dark:text-red-400';
}

export default function AdminDashboard({ stats, health }: Props) {
    return (
        <>
            <Head title="Admin" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {CARDS.map((c) => (
                        <Card key={c.key}>
                            <CardContent className="pt-6">
                                <p className="text-xs text-muted-foreground">{c.label}</p>
                                <p className="mt-1 text-2xl font-bold tracking-tight">
                                    {stats[c.key].toLocaleString()}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Catalog health — per-set valuation/image coverage */}
                <Card>
                    <CardContent className="overflow-x-auto pt-6">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-semibold">Catalog health</h2>
                            <span className="text-xs text-muted-foreground">
                                {pct(stats.valued, stats.items)}% of items valued
                            </span>
                        </div>
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="py-2 pr-3 font-medium">Set</th>
                                    <th className="py-2 pr-3 text-right font-medium">Items</th>
                                    <th className="py-2 pr-3 text-right font-medium">Valued</th>
                                    <th className="py-2 pr-3 text-right font-medium">Images</th>
                                    <th className="py-2 text-right font-medium">Coverage</th>
                                </tr>
                            </thead>
                            <tbody>
                                {health.map((s) => (
                                    <tr
                                        key={s.name}
                                        className="border-b border-border/60 last:border-0"
                                    >
                                        <td className="py-2 pr-3">
                                            <span className="font-medium">{s.name}</span>
                                            {s.code && (
                                                <span className="ml-1 text-xs text-muted-foreground">
                                                    {s.code}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 pr-3 text-right">{s.items}</td>
                                        <td
                                            className={cn(
                                                'py-2 pr-3 text-right font-medium',
                                                coverageColor(s.valued, s.items),
                                            )}
                                        >
                                            {s.valued}
                                        </td>
                                        <td className="py-2 pr-3 text-right text-muted-foreground">
                                            {s.images}
                                        </td>
                                        <td
                                            className={cn(
                                                'py-2 text-right font-semibold',
                                                coverageColor(s.valued, s.items),
                                            )}
                                        >
                                            {pct(s.valued, s.items)}%
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <div className="flex gap-3 text-sm">
                    <Link
                        href="/admin/sets"
                        className="font-medium text-primary hover:underline"
                    >
                        Manage sets →
                    </Link>
                    <Link
                        href="/admin/cards"
                        className="font-medium text-primary hover:underline"
                    >
                        Edit cards →
                    </Link>
                </div>
            </div>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [{ title: 'Admin', href: '/admin' }],
};
