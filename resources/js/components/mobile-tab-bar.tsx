import { Link, usePage } from '@inertiajs/react';
import { Home, Layers, ScanLine, Search, Store } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { dashboard, home, login } from '@/routes';

/**
 * Persistent mobile bottom tab bar (hidden ≥ lg). Renders across both the
 * public chrome and the authenticated app so mobile users always have the five
 * primary destinations. Sections not yet built (Search/Scan/Marketplace) link
 * to '#' placeholders and are wired in Phases 4–5.
 */
type Tab = {
    title: string;
    href: string;
    icon: LucideIcon;
    /** Path prefix used to mark the tab active; omitted for placeholders. */
    match?: string;
};

export function MobileTabBar() {
    const page = usePage();
    const { auth } = page.props;
    const current = page.url;

    const tabs: Tab[] = [
        { title: 'Home', href: home().url, icon: Home, match: '/' },
        { title: 'Search', href: '#', icon: Search },
        { title: 'Scan', href: '#', icon: ScanLine },
        {
            title: 'Collection',
            href: auth.user ? dashboard().url : login().url,
            icon: Layers,
            match: '/dashboard',
        },
        { title: 'Marketplace', href: '#', icon: Store },
    ];

    return (
        <nav
            aria-label="Primary"
            className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-background/95 backdrop-blur lg:hidden"
            style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
        >
            <ul className="mx-auto flex max-w-md items-stretch">
                {tabs.map((tab) => {
                    const Icon = tab.icon;
                    const isActive =
                        tab.match === '/'
                            ? current === '/'
                            : tab.match
                              ? current.startsWith(tab.match)
                              : false;

                    return (
                        <li key={tab.title} className="flex-1">
                            <Link
                                href={tab.href}
                                className={cn(
                                    'flex min-h-[44px] flex-col items-center justify-center gap-0.5 px-1 py-2 text-xs font-medium transition-colors',
                                    isActive
                                        ? 'text-primary'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                                aria-current={isActive ? 'page' : undefined}
                            >
                                <Icon className="size-5" />
                                <span>{tab.title}</span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
