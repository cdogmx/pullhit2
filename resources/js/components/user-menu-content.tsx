import { Link, router } from '@inertiajs/react';
import {
    Bell,
    CircleUserRound,
    Heart,
    LayoutGrid,
    LibraryBig,
    LogOut,
    ScanLine,
    Settings,
} from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { dashboard, logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const NAV_LINKS = [
    { title: 'Dashboard', href: dashboard().url, icon: LayoutGrid },
    { title: 'Scan', href: '/scan', icon: ScanLine },
    { title: 'Collection', href: '/collection', icon: LibraryBig },
    { title: 'Wishlist', href: '/wishlist', icon: Heart },
    { title: 'Notifications', href: '/notifications', icon: Bell },
];

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                {NAV_LINKS.map((link) => (
                    <DropdownMenuItem key={link.href} asChild>
                        <Link
                            className="block w-full cursor-pointer"
                            href={link.href}
                            prefetch
                            onClick={cleanup}
                        >
                            <link.icon className="mr-2" />
                            {link.title}
                        </Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                {user.username && (
                    <DropdownMenuItem asChild>
                        <Link
                            className="block w-full cursor-pointer"
                            href={`/u/${user.username}`}
                            onClick={cleanup}
                        >
                            <CircleUserRound className="mr-2" />
                            My profile
                        </Link>
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        Settings
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
