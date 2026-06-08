import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
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
                            tcg-platform
                        </span>
                    </Link>
                    <nav className="hidden items-center gap-1 md:flex">
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

                <div className="flex items-center gap-2">
                    {auth.user ? (
                        <>
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
                                                alt={auth.user.name}
                                            />
                                            <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                                {getInitials(auth.user.name)}
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
