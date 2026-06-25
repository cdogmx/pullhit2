import { Head, Link, router } from '@inertiajs/react';
import { Award, Rss } from 'lucide-react';
import { OwnerLink } from '@/components/shared/owner-link';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { relativeTime } from '@/lib/format';

type FeedItem = {
    id: string;
    user: { username: string | null; avatar: string | null };
    type: string;
    points: number;
    description: string | null;
    at: string | null;
};

type Props = {
    items: FeedItem[];
    followingCount: number;
    pagination: { page: number; last_page: number; total: number };
};

export default function Feed({ items, followingCount, pagination }: Props) {
    const go = (page: number) =>
        router.get(
            '/feed',
            { page },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <>
            <Head title="Feed" />
            <div className="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
                        <Rss className="size-5 text-primary" />
                        Following
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Recent activity from the {followingCount.toLocaleString()}{' '}
                        {followingCount === 1 ? 'collector' : 'collectors'} you
                        follow.
                    </p>
                </div>

                {followingCount === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-14 text-center">
                            <p className="text-sm text-muted-foreground">
                                You're not following anyone yet. Find collectors
                                to follow on the rankings.
                            </p>
                            <Button asChild size="sm">
                                <Link href="/rankings">Browse rankings</Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : items.length === 0 ? (
                    <Card>
                        <CardContent className="py-14 text-center text-sm text-muted-foreground">
                            No activity yet from people you follow.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                        {items.map((item) => (
                            <div
                                key={item.id}
                                className="flex items-start justify-between gap-3 p-3"
                            >
                                <div className="min-w-0">
                                    {item.user.username && (
                                        <OwnerLink
                                            username={item.user.username}
                                            avatar={item.user.avatar}
                                        />
                                    )}
                                    <p className="mt-1 text-sm">
                                        <span className="font-medium">
                                            {item.type}
                                        </span>
                                        {item.description && (
                                            <span className="ml-2 text-muted-foreground">
                                                {item.description}
                                            </span>
                                        )}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {item.at ? relativeTime(item.at) : ''}
                                    </p>
                                </div>
                                <Badge
                                    variant="secondary"
                                    className="shrink-0 gap-1"
                                >
                                    <Award className="size-3" />+{item.points}
                                </Badge>
                            </div>
                        ))}
                    </div>
                )}

                {pagination.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between text-sm">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pagination.page <= 1}
                            onClick={() => go(pagination.page - 1)}
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
                            onClick={() => go(pagination.page + 1)}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

Feed.layout = {
    breadcrumbs: [{ title: 'Feed', href: '/feed' }],
};
