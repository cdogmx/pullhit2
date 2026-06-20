import { Menu } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { toggleSidebar } = useSidebar();

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                {/* Mobile gets a labelled "Menu" button (the bare icon isn't
                    obvious); desktop keeps the compact icon trigger. */}
                <Button
                    variant="outline"
                    size="sm"
                    className="-ml-1 gap-1.5 md:hidden"
                    onClick={toggleSidebar}
                >
                    <Menu className="size-4" />
                    Menu
                </Button>
                <SidebarTrigger className="-ml-1 hidden md:flex" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
