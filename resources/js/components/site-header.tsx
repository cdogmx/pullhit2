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
const marketingNav = [
    { title: 'Browse', href: '/browse' },
    { title: 'Features', href: '/#features' },
    { title: 'How it works', href: '/#how-it-works' },
];

// Catalog dropdowns by vertical/product line. Languages link into the SEO
// product-line landing with a language filter (e.g. /browse/pokemon?language=ja).
const catalogMenus = [
    {
        title: 'Pokémon',
        items: [
            { label: 'All Pokémon', href: '/browse/pokemon' },
            { label: 'English', href: '/browse/pokemon?language=en' },
            { label: 'Japanese', href: '/browse/pokemon?language=ja' },
        ],
    },
    {
        title: 'One Piece',
        items: [
            { label: 'All One Piece', href: '/browse/one-piece' },
            { label: 'English', href: '/browse/one-piece?language=en' },
            { label: 'Japanese', href: '/browse/one-piece?language=ja' },
        ],
    },
    {
        title: 'Disney Lorcana',
        items: [{ label: 'All Disney Lorcana', href: '/browse/lorcana' }],
    },
];

export function SiteHeader() {
    const { auth } = usePage().props;
    const getInitials = useInitials();

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
                        {catalogMenus.map((menu) => (
                            <DropdownMenu key={menu.title}>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-1 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus:outline-none"
                                    >
                                        {menu.title}
                                        <ChevronDown className="size-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="start"
                                    className="w-44"
                                >
                                    {menu.items.map((item) => (
                                        <DropdownMenuItem
                                            key={item.href}
                                            asChild
                                        >
                                            <Link href={item.href}>
                                                {item.label}
                                            </Link>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ))}
                        {marketingNav.map((item) => (
                            <a
                                key={item.href}
                                href={item.href}
                                className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                            >
                                {item.title}
                            </a>
                        ))}
                    </nav>
                </div>

                <SiteSearch className="hidden max-w-sm flex-1 md:block" />

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
