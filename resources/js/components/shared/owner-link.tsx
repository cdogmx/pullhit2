import { Link } from '@inertiajs/react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';

/**
 * An avatar + @handle that links to a user's public profile. Used as the owner
 * attribution on public collection / wishlist pages.
 */
export function OwnerLink({
    username,
    avatar,
}: {
    username: string;
    avatar?: string | null;
}) {
    const getInitials = useInitials();

    return (
        <Link
            href={`/u/${username}`}
            className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
        >
            <Avatar className="size-6">
                <AvatarImage src={avatar ?? undefined} alt={username} />
                <AvatarFallback className="bg-primary/15 text-[10px] font-bold text-primary">
                    {getInitials(username)}
                </AvatarFallback>
            </Avatar>
            @{username}
        </Link>
    );
}
