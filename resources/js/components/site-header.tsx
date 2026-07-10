import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, Menu, Zap } from 'lucide-react';
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
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { dashboard, home, login, register } from '@/routes';
import type { Auth } from '@/types';

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
                <div className="flex items-center gap-3 md:gap-6">
                    <MobileMenu auth={auth} />
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

                        <a href="/movers" className={NAV_LINK}>
                            Movers
                        </a>
                        <a href="/compare" className={NAV_LINK}>
                            Compare
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

/**
 * Mobile hamburger menu (hidden ≥ md) — a left drawer surfacing every header
 * nav link, since the desktop bar is hidden on small screens and the bottom
 * tab bar only carries the five primary destinations. Each link closes the
 * drawer on navigation via SheetClose.
 */
function MobileMenu({ auth }: { auth: Auth }) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="md:hidden"
                    aria-label="Open menu"
                >
                    <Menu className="size-5" />
                </Button>
            </SheetTrigger>
            <SheetContent side="left" className="w-80 overflow-y-auto">
                <SheetHeader>
                    <SheetTitle>Menu</SheetTitle>
                </SheetHeader>

                <nav className="flex flex-col px-2 pb-8">
                    <MenuHeading>Browse</MenuHeading>
                    <MenuLink href="/browse">All cards</MenuLink>
                    {catalogMenu.map((game) =>
                        game.links ? (
                            <div key={game.label}>
                                <p className="px-3 pt-2 text-xs font-medium text-muted-foreground">
                                    {game.label}
                                </p>
                                {game.links.map((l) => (
                                    <MenuLink key={l.href} href={l.href} indent>
                                        {l.label}
                                    </MenuLink>
                                ))}
                            </div>
                        ) : (
                            <MenuLink key={game.href} href={game.href!}>
                                {game.label}
                            </MenuLink>
                        ),
                    )}

                    <MenuHeading>Explore</MenuHeading>
                    <MenuLink href="/movers">Movers</MenuLink>
                    <MenuLink href="/compare">Compare</MenuLink>
                    <MenuLink href="/rip-or-keep">Rip or Keep?</MenuLink>
                    {moreMenu.map((item) => (
                        <MenuLink key={item.href} href={item.href}>
                            {item.label}
                        </MenuLink>
                    ))}

                    {auth.user ? (
                        <>
                            <MenuHeading>Account</MenuHeading>
                            <MenuLink href={dashboard().url}>Dashboard</MenuLink>
                        </>
                    ) : (
                        <>
                            <MenuHeading>Account</MenuHeading>
                            <MenuLink href={login().url}>Log in</MenuLink>
                            <MenuLink href={register().url}>Get started</MenuLink>
                        </>
                    )}
                </nav>
            </SheetContent>
        </Sheet>
    );
}

function MenuHeading({ children }: { children: React.ReactNode }) {
    return (
        <p className="px-3 pt-4 pb-1 text-xs font-semibold tracking-wide text-muted-foreground/70 uppercase">
            {children}
        </p>
    );
}

function MenuLink({
    href,
    children,
    indent = false,
}: {
    href: string;
    children: React.ReactNode;
    indent?: boolean;
}) {
    const className = cn(
        'block rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
        indent && 'pl-6',
    );

    // Hash links (e.g. /#features) need a real anchor to scroll; the rest are
    // client-side Inertia navigations.
    return (
        <SheetClose asChild>
            {href.includes('#') ? (
                <a href={href} className={className}>
                    {children}
                </a>
            ) : (
                <Link href={href} className={className}>
                    {children}
                </Link>
            )}
        </SheetClose>
    );
}
