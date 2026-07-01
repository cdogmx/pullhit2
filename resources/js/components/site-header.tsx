import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, Zap } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { SiteSearch } from '@/components/site-search';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { dashboard, home, login, register } from '@/routes';

/**
 * Shared marketing/public chrome header. Auth-aware: shows sign-in actions for
 * guests and an account menu (+ Dashboard link) for authenticated users. The
 * authenticated app keeps the sidebar layout; this header wraps public pages.
 */
// The catalog, consolidated under one "Browse" menu. A game with multiple
// languages becomes a submenu (Japanese links into the SEO landing with a
// language filter); single-language games are plain links.
const catalogMenu: {
    label: string;
    href?: string;
    links?: { label: string; href: string }[];
}[] = [
    {
        label: 'Pokémon',
        links: [
            { label: 'All Pokémon', href: '/browse/pokemon' },
            { label: 'Japanese', href: '/browse/pokemon?language=ja' },
        ],
    },
    {
        label: 'One Piece',
        links: [
            { label: 'All One Piece', href: '/browse/one-piece' },
            { label: 'Japanese', href: '/browse/one-piece?language=ja' },
        ],
    },
    { label: 'Disney Lorcana', href: '/browse/lorcana' },
    { label: 'Cyberpunk', href: '/browse/cyberpunk' },
];

// Secondary links, tucked under a "More" menu to keep the bar uncluttered.
const moreMenu = [
    { label: 'Giveaways', href: '/rankings' },
    { label: 'Features', href: '/#features' },
];

const NAV_TRIGGER =
    'inline-flex items-center gap-1 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus:outline-none';
const NAV_LINK =
    'rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground';

export function SiteHeader() {
    const page = usePage();
    const { auth } = page.props;
    const getInitials = useInitials();

    // The homepage hero and the browse pages already have a prominent search
    // bar — don't double up with the header one there.
    const path = page.url.split('?')[0];
    const hideSearch = path === '/' || path.startsWith('/browse');

    return (
        <header className="sticky top-0 z-40 w-full border-b border-border bg-background/80 backdrop-blur">
            <div className="mx-auto flex h-16 w-full max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                <div className="flex items-center gap-6">
                    <Link
                        href={home()}
                        className="flex items-center gap-2"
                        aria-label="Home"
                    >
                        <span className="flex aspect-square size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                            <AppLogoIcon className="size-5 fill-current" />
                        </span>
                        <span className="text-base font-semibold tracking-tight">
                            CardFoo
                        </span>
                    </Link>
                    <nav className="hidden items-center gap-1 md:flex">
                        {/* Browse — the whole catalog under one menu. */}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button type="button" className={NAV_TRIGGER}>
                                    Browse
                                    <ChevronDown className="size-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" className="w-48">
                                <DropdownMenuItem asChild>
                                    <Link href="/browse">All cards</Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                {catalogMenu.map((game) =>
                                    game.links ? (
                                        <DropdownMenuSub key={game.label}>
                                            <DropdownMenuSubTrigger>
                                                {game.label}
                                            </DropdownMenuSubTrigger>
                                            <DropdownMenuSubContent>
                                                {game.links.map((l) => (
                                                    <DropdownMenuItem
                                                        key={l.href}
                                                        asChild
                                                    >
                                                        <Link href={l.href}>
                                                            {l.label}
                                                        </Link>
                                                    </DropdownMenuItem>
                                                ))}
                                            </DropdownMenuSubContent>
                                        </DropdownMenuSub>
                                    ) : (
                                        <DropdownMenuItem
                                            key={game.href}
                                            asChild
                                        >
                                            <Link href={game.href!}>
                                                {game.label}
                                            </Link>
                                        </DropdownMenuItem>
                                    ),
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <a href="/deals" className={NAV_LINK}>
                            Deals
                        </a>
                        <a href="/rip-or-keep" className={NAV_LINK}>
                            Rip or Keep?
                        </a>

                        {/* More — secondary links, kept out of the main bar. */}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button type="button" className={NAV_TRIGGER}>
                                    More
                                    <ChevronDown className="size-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" className="w-44">
                                {moreMenu.map((item) => (
                                    <DropdownMenuItem key={item.href} asChild>
                                        <a href={item.href}>{item.label}</a>
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </nav>
                </div>

                {!hideSearch && (
                    <SiteSearch className="hidden max-w-sm flex-1 md:block" />
                )}

                <div className="flex items-center gap-2">
                    {auth.user ? (
                        <>
                            {(auth.user.membership_tier ?? 'free') === 'free' &&
                                !auth.user.is_admin && (
                                    <Button asChild size="sm">
                                        <Link href="/settings/billing">
                                            <Zap className="size-4" />
                                            Upgrade
                                        </Link>
                                    </Button>
                                )}
                            <Button
                                asChild
                                variant="ghost"
                                size="sm"
                                className="hidden sm:inline-flex"
                            >
                                <Link href={dashboard()}>Dashboard</Link>
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="rounded-full"
                                        aria-label="Account menu"
                                    >
                                        <Avatar className="size-8 overflow-hidden rounded-full">
                                            <AvatarImage
                                                src={auth.user.avatar}
                                                alt={
                                                    auth.user.username ||
                                                    auth.user.name
                                                }
                                            />
                                            <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                                {getInitials(
                                                    auth.user.username ||
                                                        auth.user.name,
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    className="w-56 rounded-lg"
                                    align="end"
                                >
                                    <UserMenuContent user={auth.user} />
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </>
                    ) : (
                        <>
                            <Button
                                asChild
                                variant="ghost"
                                size="sm"
                                className="hidden sm:inline-flex"
                            >
                                <Link href={login()}>Log in</Link>
                            </Button>
                            <Button asChild size="sm">
                                <Link href={register()}>Get started</Link>
                            </Button>
                        </>
                    )}
                </div>
            </div>
        </header>
    );
}
