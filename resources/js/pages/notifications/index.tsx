import { Head, Link, router } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type NotificationRow = {
    id: string;
    title: string;
    body: string;
    url: string | null;
    read: boolean;
    created_at: string;
};

export default function Notifications({
    notifications,
}: {
    notifications: NotificationRow[];
}) {
    const hasUnread = notifications.some((n) => !n.read);

    const markAllRead = () => {
        router.post(
            '/notifications/read-all',
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Notifications" />

            <div className="mx-auto w-full max-w-2xl flex-1 p-4">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">Notifications</h1>
                    {hasUnread && (
                        <Button variant="outline" size="sm" onClick={markAllRead}>
                            Mark all as read
                        </Button>
                    )}
                </div>

                {notifications.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-border py-16 text-muted-foreground">
                        <Bell className="size-8" />
                        <p className="text-sm">You're all caught up.</p>
                    </div>
                ) : (
                    <ul className="divide-y divide-border rounded-lg border border-border">
                        {notifications.map((n) => {
                            const inner = (
                                <div className="flex items-start gap-3 px-4 py-3">
                                    <span
                                        className={cn(
                                            'mt-1.5 size-2 shrink-0 rounded-full',
                                            n.read
                                                ? 'bg-transparent'
                                                : 'bg-primary',
                                        )}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p
                                            className={cn(
                                                'text-sm',
                                                n.read
                                                    ? 'text-muted-foreground'
                                                    : 'font-medium',
                                            )}
                                        >
                                            {n.title}
                                        </p>
                                        {n.body && (
                                            <p className="text-sm text-muted-foreground">
                                                {n.body}
                                            </p>
                                        )}
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {n.created_at}
                                        </p>
                                    </div>
                                </div>
                            );

                            return (
                                <li key={n.id}>
                                    {n.url ? (
                                        <Link
                                            href={`/notifications/${n.id}`}
                                            className="block transition-colors hover:bg-accent/40"
                                        >
                                            {inner}
                                        </Link>
                                    ) : (
                                        inner
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Notifications', href: '/notifications' }],
};
