import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { MobileTabBar } from '@/components/mobile-tab-bar';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            {/* Bottom padding clears the fixed mobile tab bar (hidden ≥ lg). */}
            <AppContent
                variant="sidebar"
                className="bg-diagonal overflow-x-hidden pb-20 lg:pb-0"
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
            <MobileTabBar />
        </AppShell>
    );
}
