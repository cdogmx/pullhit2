import { Head, Link } from '@inertiajs/react';
import { Award, Gift, Heart, LibraryBig, Pencil, Trophy } from 'lucide-react';
import { ProfileLinks } from '@/components/profile-links';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useInitials } from '@/hooks/use-initials';
import { formatMoney, relativeTime } from '@/lib/format';

type Level = {
    name: string;
    points: number;
    into_level: number;
    to_next: number | null;
    next_at: number | null;
};

type Profile = {
    username: string;
    avatar: string | null;
    bio: string | null;
    location: string | null;
    website: string | null;
    x_handle: string | null;
    instagram_handle: string | null;
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

type Breakdown = { type: string; count: number; points: number };
type Win = { period_label: string; prize: string; image: string | null };
type Cover = {
    name: string | null;
    image_url: string | null;
    url: string | null;
};
type Showcase = {
    url: string;
    card_count: number;
    total_value: number;
    currency: string;
    covers: Cover[];
} | null;

type Props = {
    profile: Profile;
    recent: Recent[];
    breakdown: Breakdown[];
    wins: Win[];
    showcase: Showcase;
    isOwner: boolean;
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

export default function ProfileShow({
    profile,
    recent,
    breakdown,
    wins,
    showcase,
    isOwner,
    month,
}: Props) {
    const getInitials = useInitials();
    const memberSince = profile.member_since
        ? new Date(profile.member_since).toLocaleDateString(undefined, {
              month: 'long',
              year: 'numeric',
          })
        : null;

    const { into_level, to_next } = profile.level;
    const levelPct =
        to_next != null && into_level + to_next > 0
            ? Math.min(
                  100,
                  Math.round((into_level / (into_level + to_next)) * 100),
              )
            : 100;

    return (
        <>
            <Head title={`@${profile.username} — CardFoo`} />
            <div className="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex flex-col items-center gap-4 text-center sm:flex-row sm:items-start sm:text-left">
                    <Avatar className="size-20 rounded-2xl">
                        <AvatarImage
                            src={profile.avatar ?? undefined}
                            alt={profile.username}
                        />
                        <AvatarFallback className="rounded-2xl bg-primary/15 text-xl font-bold text-primary">
                            {getInitials(profile.username)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                        <div className="flex items-start justify-center gap-3 sm:justify-between">
                            <h1 className="text-2xl font-bold tracking-tight">
                                @{profile.username}
                            </h1>
                            {isOwner && (
                                <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                    className="hidden sm:inline-flex"
                                >
                                    <Link href="/settings/profile">
                                        <Pencil className="size-4" />
                                        Edit profile
                                    </Link>
                                </Button>
                            )}
                        </div>
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

                        {profile.bio && (
                            <p className="mt-3 text-sm text-foreground/90">
                                {profile.bio}
                            </p>
                        )}

                        <ProfileLinks
                            location={profile.location}
                            website={profile.website}
                            x_handle={profile.x_handle}
                            instagram_handle={profile.instagram_handle}
                            className="mt-3 justify-center sm:justify-start"
                        />

                        {isOwner && (
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="mt-3 sm:hidden"
                            >
                                <Link href="/settings/profile">
                                    <Pencil className="size-4" />
                                    Edit profile
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {/* Level progress */}
                <div className="mt-6">
                    <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                        <span className="font-medium text-foreground">
                            {profile.level.name}
                        </span>
                        <span>
                            {to_next != null
                                ? `${to_next.toLocaleString()} pts to next level`
                                : 'Max level reached'}
                        </span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-primary transition-all"
                            style={{ width: `${levelPct}%` }}
                        />
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

                {/* Giveaway wins */}
                {wins.length > 0 && (
                    <div className="mt-8">
                        <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold">
                            <Trophy className="size-4 text-primary" />
                            Giveaway wins
                        </h2>
                        <div className="flex flex-wrap gap-3">
                            {wins.map((w, i) => (
                                <div
                                    key={i}
                                    className="flex items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 p-3"
                                >
                                    {w.image ? (
                                        <img
                                            src={w.image}
                                            alt={w.prize}
                                            className="size-10 rounded-md object-contain"
                                        />
                                    ) : (
                                        <Trophy className="size-8 text-primary" />
                                    )}
                                    <div>
                                        <p className="text-sm font-semibold">
                                            {w.prize}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Won {w.period_label}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Collection showcase */}
                {showcase && (
                    <div className="mt-8">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="flex items-center gap-1.5 text-sm font-semibold">
                                <LibraryBig className="size-4 text-primary" />
                                Collection
                            </h2>
                            <Link
                                href={showcase.url}
                                className="text-sm font-medium text-primary hover:underline"
                            >
                                View all &rarr;
                            </Link>
                        </div>
                        <p className="mb-3 text-sm text-muted-foreground">
                            {showcase.card_count.toLocaleString()} cards ·{' '}
                            <span className="font-semibold text-foreground">
                                {formatMoney(
                                    showcase.total_value,
                                    showcase.currency,
                                )}
                            </span>{' '}
                            estimated value
                        </p>
                        {showcase.covers.length > 0 && (
                            <div className="flex gap-3 overflow-x-auto pb-2">
                                {showcase.covers.map((c, i) => {
                                    const img = (
                                        <img
                                            src={c.image_url ?? undefined}
                                            alt={c.name ?? ''}
                                            loading="lazy"
                                            className="h-36 w-auto rounded-lg border border-border bg-muted"
                                        />
                                    );

                                    return c.url ? (
                                        <Link
                                            key={i}
                                            href={c.url}
                                            className="shrink-0"
                                        >
                                            {img}
                                        </Link>
                                    ) : (
                                        <span key={i} className="shrink-0">
                                            {img}
                                        </span>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                )}

                {/* Public links */}
                <div className="mt-6 flex flex-wrap gap-2">
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

                {/* Contribution breakdown */}
                {breakdown.length > 0 && (
                    <div className="mt-8 flex flex-wrap gap-2">
                        {breakdown.map((b) => (
                            <span
                                key={b.type}
                                className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1 text-xs"
                            >
                                <span className="font-medium">{b.type}</span>
                                <span className="text-muted-foreground">
                                    {b.count.toLocaleString()}
                                </span>
                            </span>
                        ))}
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
                                            <td className="px-3 py-2 text-right text-xs whitespace-nowrap text-muted-foreground">
                                                {r.at ? relativeTime(r.at) : ''}
                                            </td>
                                            <td className="px-3 py-2 text-right font-semibold whitespace-nowrap text-primary">
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
