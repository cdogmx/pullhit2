import { Head, Link } from '@inertiajs/react';
import { Award, Gift, Heart, LibraryBig, Trophy } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useInitials } from '@/hooks/use-initials';
import { relativeTime } from '@/lib/format';

type Level = {
    name: string;
    points: number;
    to_next: number | null;
};

type Profile = {
    username: string;
    avatar: string | null;
    level: Level;
    points: number;
    rank: number | null;
    entries: number;
    contributions: number;
    member_since: string | null;
    collection_url: string | null;
    wishlist_url: string | null;
};

type Recent = {
    type: string;
    points: number;
    description: string | null;
    at: string | null;
};

type Props = {
    profile: Profile;
    recent: Recent[];
    month: string;
};

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4 text-center">
            <p className="text-2xl font-bold">{value}</p>
            <p className="text-xs text-muted-foreground">{label}</p>
        </div>
    );
}

export default function ProfileShow({ profile, recent, month }: Props) {
    const getInitials = useInitials();
    const memberSince = profile.member_since
        ? new Date(profile.member_since).toLocaleDateString(undefined, {
              month: 'long',
              year: 'numeric',
          })
        : null;

    return (
        <>
            <Head title={`@${profile.username} — CardFoo`} />
            <div className="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex flex-col items-center gap-4 text-center sm:flex-row sm:text-left">
                    <Avatar className="size-20 rounded-2xl">
                        <AvatarImage
                            src={profile.avatar ?? undefined}
                            alt={profile.username}
                        />
                        <AvatarFallback className="rounded-2xl bg-primary/15 text-xl font-bold text-primary">
                            {getInitials(profile.username)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="flex-1">
                        <h1 className="text-2xl font-bold tracking-tight">
                            @{profile.username}
                        </h1>
                        <div className="mt-1 flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                            <Badge className="gap-1">
                                <Award className="size-3.5" />
                                {profile.level.name}
                            </Badge>
                            {memberSince && (
                                <span className="text-sm text-muted-foreground">
                                    Member since {memberSince}
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                {/* Stats */}
                <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Stat
                        label="points"
                        value={profile.points.toLocaleString()}
                    />
                    <Stat
                        label="all-time rank"
                        value={profile.rank ? `#${profile.rank}` : '—'}
                    />
                    <Stat
                        label={`${month} entries`}
                        value={profile.entries.toLocaleString()}
                    />
                    <Stat
                        label="contributions"
                        value={profile.contributions.toLocaleString()}
                    />
                </div>

                {/* Public links */}
                {(profile.collection_url || profile.wishlist_url) && (
                    <div className="mt-4 flex flex-wrap gap-2">
                        {profile.collection_url && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={profile.collection_url}>
                                    <LibraryBig className="size-4" />
                                    Collection
                                </Link>
                            </Button>
                        )}
                        {profile.wishlist_url && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={profile.wishlist_url}>
                                    <Heart className="size-4" />
                                    Wishlist
                                </Link>
                            </Button>
                        )}
                        <Button asChild variant="ghost" size="sm">
                            <Link href="/rankings">
                                <Trophy className="size-4" />
                                Rankings
                            </Link>
                        </Button>
                    </div>
                )}

                {/* Recent contributions */}
                <div className="mt-8">
                    <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold">
                        <Gift className="size-4 text-primary" />
                        Recent contributions
                    </h2>
                    {recent.length === 0 ? (
                        <Card>
                            <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                No contributions yet.
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="overflow-hidden rounded-xl border border-border">
                            <table className="w-full text-sm">
                                <tbody>
                                    {recent.map((r, i) => (
                                        <tr
                                            key={i}
                                            className="border-b border-border/60 last:border-0"
                                        >
                                            <td className="px-3 py-2">
                                                <span className="font-medium">
                                                    {r.type}
                                                </span>
                                                {r.description && (
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        {r.description}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right text-xs text-muted-foreground">
                                                {r.at ? relativeTime(r.at) : ''}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right font-semibold text-primary">
                                                +{r.points}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
