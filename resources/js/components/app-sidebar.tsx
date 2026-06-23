import { Link, usePage } from '@inertiajs/react';
import {
    Anchor,
    Award,
    Bell,
    Boxes,
    CreditCard,
    FlagTriangleRight,
    Gift,
    GitCompare,
    Globe,
    Heart,
    Inbox,
    Languages,
    LayoutGrid,
    LibraryBig,
    Library,
    PencilRuler,
    Radar,
    ScanLine,
    Search,
    Shield,
    Sparkles,
    Users,
    Zap,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Scan',
        href: '/scan',
        icon: ScanLine,
    },
    {
        title: 'Collection',
        href: '/collection',
        icon: LibraryBig,
    },
    {
        title: 'Wishlist',
        href: '/wishlist',
        icon: Heart,
    },
    {
        title: 'Notifications',
        href: '/notifications',
        icon: Bell,
    },
    {
        title: 'Contribute',
        href: '/contribute',
        icon: Award,
    },
    {
        title: 'Browse',
        href: '/browse',
        icon: Search,
    },
    {
        title: 'One Piece',
        href: '/browse/one-piece',
        icon: Anchor,
    },
    {
        title: 'Disney Lorcana',
        href: '/browse/lorcana',
        icon: Sparkles,
    },
];

// Catalog by product line + language, linking the SEO landings.
const pokemonNavItems: NavItem[] = [
    { title: 'All Pokémon', href: '/browse/pokemon', icon: Globe },
    { title: 'English', href: '/browse/pokemon?language=en', icon: Languages },
    { title: 'Japanese', href: '/browse/pokemon?language=ja', icon: Languages },
];

const adminNavItems: NavItem[] = [
    { title: 'Admin', href: '/admin', icon: Shield },
    { title: 'Users', href: '/admin/users', icon: Users },
    { title: 'Transactions', href: '/admin/transactions', icon: CreditCard },
    { title: 'Brands', href: '/admin/brands', icon: Boxes },
    { title: 'Sets', href: '/admin/sets', icon: Library },
    { title: 'Cards', href: '/admin/cards', icon: PencilRuler },
    { title: 'Suggestions', href: '/admin/suggestions', icon: Inbox },
    { title: 'Card reports', href: '/admin/card-reports', icon: FlagTriangleRight },
    { title: 'Giveaways', href: '/admin/giveaways', icon: Gift },
    { title: 'eBay sweep', href: '/admin/ebay-sweep', icon: Radar },
    { title: 'Scan feedback', href: '/admin/scan-feedback', icon: ScanLine },
    { title: 'Reconcile', href: '/admin/reconcile', icon: GitCompare },
];

export function AppSidebar() {
    const page = usePage().props as {
        auth?: {
            user?: {
                is_admin?: boolean;
                membership_tier?: string;
            };
        };
        pendingSuggestions?: number | null;
        unreadNotifications?: number | null;
    };
    const isAdmin = Boolean(page.auth?.user?.is_admin);
    const isFree =
        Boolean(page.auth?.user) &&
        !isAdmin &&
        (page.auth?.user?.membership_tier ?? 'free') === 'free';

    // Badge the review queue with its pending count (shared from the server).
    const adminNav = adminNavItems.map((item) =>
        item.href === '/admin/suggestions'
            ? { ...item, badge: page.pendingSuggestions || null }
            : item,
    );

    // Badge Notifications with the unread count.
    const mainNav = mainNavItems.map((item) =>
        item.href === '/notifications'
            ? { ...item, badge: page.unreadNotifications || null }
            : item,
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNav} />
                <NavMain items={pokemonNavItems} label="Pokémon" />
                {isAdmin && <NavMain items={adminNav} label="Admin" />}

                {isFree && (
                    <div className="mt-auto px-3 pb-2 group-data-[collapsible=icon]:hidden">
                        <Link
                            href="/settings/billing"
                            className="block rounded-lg border border-primary/30 bg-primary/10 p-3 text-center transition-colors hover:bg-primary/15"
                        >
                            <span className="flex items-center justify-center gap-1.5 text-sm font-semibold">
                                <Zap className="size-4 text-primary" />
                                Upgrade
                            </span>
                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                More scans, collections &amp; wishlists
                            </span>
                        </Link>
                    </div>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
