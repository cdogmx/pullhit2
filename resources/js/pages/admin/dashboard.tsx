import { Head, Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import type { AdminStats } from '@/types';

type Props = { stats: AdminStats };

const CARDS: { key: keyof AdminStats; label: string }[] = [
    { key: 'sets', label: 'Sets' },
    { key: 'items', label: 'Catalog items' },
    { key: 'valued', label: 'Valued items' },
    { key: 'images', label: 'Images stored' },
    { key: 'users', label: 'Users' },
    { key: 'premium', label: 'Premium' },
    { key: 'admins', label: 'Admins' },
];

export default function AdminDashboard({ stats }: Props) {
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
