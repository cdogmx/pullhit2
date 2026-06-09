import type { ReactNode } from 'react';
import { MobileTabBar } from '@/components/mobile-tab-bar';
import { SiteFooter } from '@/components/site-footer';
import { SiteHeader } from '@/components/site-header';

/**
 * AppShell — the persistent public/marketing layout. Wraps marketing pages
 * (and future public catalog/marketplace browse) with the shared header,
 * footer, and mobile bottom tab bar. The authenticated app uses the sidebar
 * layout; the bottom tab bar renders in both so mobile nav is app-wide.
 */
export default function AppShellLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-svh flex-col bg-background text-foreground">
            <SiteHeader />
            {/* Bottom padding clears the fixed mobile tab bar (hidden ≥ lg). */}
            <main className="bg-diagonal flex-1 pb-20 lg:pb-0">{children}</main>
            <SiteFooter />
            <MobileTabBar />
        </div>
    );
}
