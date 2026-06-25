import { Globe, MapPin } from 'lucide-react';
import { cn } from '@/lib/utils';

export type ProfileSocials = {
    location?: string | null;
    website?: string | null;
    x_handle?: string | null;
    instagram_handle?: string | null;
};

function hostname(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}

/**
 * A compact row of a user's location + website + social links. Shared by the
 * public profile page and the public collection/wishlist owner headers. Renders
 * nothing when the user has set none of them.
 */
export function ProfileLinks({
    location,
    website,
    x_handle,
    instagram_handle,
    className,
}: ProfileSocials & { className?: string }) {
    if (!location && !website && !x_handle && !instagram_handle) {
        return null;
    }

    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground',
                className,
            )}
        >
            {location && (
                <span className="inline-flex items-center gap-1">
                    <MapPin className="size-4" />
                    {location}
                </span>
            )}
            {website && (
                <a
                    href={website}
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    className="inline-flex items-center gap-1 hover:text-foreground"
                >
                    <Globe className="size-4" />
                    {hostname(website)}
                </a>
            )}
            {x_handle && (
                <a
                    href={`https://x.com/${x_handle}`}
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    className="inline-flex items-center gap-1 hover:text-foreground"
                >
                    <XIcon className="size-3.5" />@{x_handle}
                </a>
            )}
            {instagram_handle && (
                <a
                    href={`https://instagram.com/${instagram_handle}`}
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    className="inline-flex items-center gap-1 hover:text-foreground"
                >
                    <InstagramIcon className="size-4" />@{instagram_handle}
                </a>
            )}
        </div>
    );
}

function XIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            className={className}
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
        </svg>
    );
}

function InstagramIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            className={className}
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect x="2" y="2" width="20" height="20" rx="5" />
            <circle cx="12" cy="12" r="4" />
            <circle cx="17.5" cy="6.5" r="0.5" fill="currentColor" />
        </svg>
    );
}
