import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { OwnerLink } from '@/components/shared/owner-link';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type Person = { username: string; avatar: string | null; level: string };

type Props = {
    username: string;
    type: 'followers' | 'following';
    people: Person[];
    counts: { followers: number; following: number };
};

export default function Follows({ username, type, people, counts }: Props) {
    const tab = (active: boolean) =>
        cn(
            'border-b-2 px-1 pb-2 text-sm font-medium transition-colors',
            active
                ? 'border-primary text-foreground'
                : 'border-transparent text-muted-foreground hover:text-foreground',
        );

    return (
        <>
            <Head title={`@${username} · ${type}`} />
            <div className="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
                <Link
                    href={`/u/${username}`}
                    className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />@{username}
                </Link>

                <div className="mt-4 flex gap-6 border-b border-border">
                    <Link
                        href={`/u/${username}/followers`}
                        className={tab(type === 'followers')}
                    >
                        {counts.followers.toLocaleString()} followers
                    </Link>
                    <Link
                        href={`/u/${username}/following`}
                        className={tab(type === 'following')}
                    >
                        {counts.following.toLocaleString()} following
                    </Link>
                </div>

                {people.length === 0 ? (
                    <p className="py-16 text-center text-sm text-muted-foreground">
                        {type === 'followers'
                            ? 'No followers yet.'
                            : 'Not following anyone yet.'}
                    </p>
                ) : (
                    <div className="mt-4 divide-y divide-border overflow-hidden rounded-xl border border-border">
                        {people.map((p) => (
                            <div
                                key={p.username}
                                className="flex items-center justify-between p-3"
                            >
                                <OwnerLink
                                    username={p.username}
                                    avatar={p.avatar}
                                />
                                <Badge variant="secondary">{p.level}</Badge>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
