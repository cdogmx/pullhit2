import { Link, usePage } from '@inertiajs/react';
import {
    Globe,
    Languages,
    LayoutGrid,
    LibraryBig,
    Library,
    PencilRuler,
    ScanLine,
    Search,
    Shield,
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
        title: 'Browse',
        href: '/browse',
        icon: Search,
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
    { title: 'Sets', href: '/admin/sets', icon: Library },
    { title: 'Cards', href: '/admin/cards', icon: PencilRuler },
];

export function AppSidebar() {
    const isAdmin = Boolean(usePage().props.auth?.user?.is_admin);

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
                <NavMain items={mainNavItems} />
                <NavMain items={pokemonNavItems} label="Pokémon" />
                {isAdmin && <NavMain items={adminNavItems} label="Admin" />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
