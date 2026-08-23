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
    HeartPulse,
    Globe,
    Heart,
    Inbox,
    Languages,
    LayoutGrid,
    Library,
    ListTree,
    PencilRuler,
    Radar,
    Rss,
    ScanLine,
    Search,
    Shield,
    Tag,
    Sparkles,
    TrendingDown,
    TrendingUp,
    Trophy,
    Users,
    Zap,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavCollections } from '@/components/nav-collections';
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
        title: 'Wishlist',
        href: '/wishlist',
        icon: Heart,
    },
    {
        title: 'Feed',
        href: '/feed',
        icon: Rss,
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
        title: 'Rankings',
        href: '/rankings',
        icon: Trophy,
    },
    {
        title: 'Browse',
        href: '/browse',
        icon: Search,
    },
    {
        title: 'Deals',
        href: '/deals',
        icon: Tag,
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

// Ordered by the catalog hierarchy (Structure → Brand → Set → Card), then the
// review/moderation queues, pricing ops, and platform admin.
const adminNavItems: NavItem[] = [
    { title: 'Admin', href: '/admin', icon: Shield },

    // Catalog — brand → series → set → subset → card
    { title: 'Structure', href: '/admin/structure', icon: ListTree },
    { title: 'Brands', href: '/admin/brands', icon: Boxes },
    { title: 'Sets', href: '/admin/sets', icon: Library },
    { title: 'Set health', href: '/admin/set-health', icon: HeartPulse },
    { title: 'Cards', href: '/admin/cards', icon: PencilRuler },

    // Review & moderation
    { title: 'Suggestions', href: '/admin/suggestions', icon: Inbox },
    {
        title: 'Card reports',
        href: '/admin/card-reports',
        icon: FlagTriangleRight,
    },
    { title: 'Reconcile', href: '/admin/reconcile', icon: GitCompare },
    { title: 'Scan feedback', href: '/admin/scan-feedback', icon: ScanLine },

    // Pricing & availability ops
    { title: 'Grading gaps', href: '/admin/grading-gaps', icon: TrendingUp },
    {
        title: 'Price inversions',
        href: '/admin/price-inversions',
        icon: TrendingDown,
    },
    { title: 'eBay sweep', href: '/admin/ebay-sweep', icon: Radar },
    { title: 'Stock alerts', href: '/admin/stock-alerts', icon: Bell },

    // Platform
    { title: 'Users', href: '/admin/users', icon: Users },
    { title: 'Transactions', href: '/admin/transactions', icon: CreditCard },
    { title: 'Giveaways', href: '/admin/giveaways', icon: Gift },
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
                <NavCollections />
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
