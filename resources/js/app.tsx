import { createInertiaApp, router } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AppShellLayout from '@/layouts/app-shell-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'CardFoo';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'brand':
            case name === 'terms':
            case name === 'privacy':
            case name === 'rankings':
            case name === 'deals':
            case name === 'movers':
            case name === 'price-race':
            case name === 'rip-or-keep/index':
            case name === 'profile/show':
            case name === 'profile/follows':
            case name === 'collection/public':
            case name === 'wishlist/public':
            case name.startsWith('catalog/'):
            // The marketplace is its own world, not a panel inside the app:
            // browsing it and reading a listing are public, and a buyer who
            // followed a shared link has no account and no business being
            // dropped into somebody's dashboard chrome. Only the management
            // side of it — your own listings, your conversations — lives in
            // the dashboard, and those stay on the default layout below.
            case name === 'marketplace/index':
            case name === 'marketplace/show':
                return AppShellLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// iOS Safari's Share sheet (and many apps) read <link rel="canonical"> / og:url
// rather than the address bar. Blade renders those once on the initial load, so
// after a client-side Inertia navigation they still point at the first page —
// making Share send the wrong URL. Re-sync them to the current page on every
// navigation. A page may pass its own share URL via meta.url (browse/search send
// the full URL so shared searches keep their query); everything else is
// path-only, matching the server's url()->current().
router.on('navigate', (event) => {
    const meta = (
        event.detail?.page?.props as { meta?: { url?: string } } | undefined
    )?.meta;
    const url = meta?.url ?? window.location.origin + window.location.pathname;

    document.querySelector('link[rel="canonical"]')?.setAttribute('href', url);
    document
        .querySelector('meta[property="og:url"]')
        ?.setAttribute('content', url);
});

// Google Analytics (gtag.js) is loaded in app.blade.php and fires a page_view
// for the initial document load. Inertia navigations don't reload the page, so
// send a GA4 page_view on each client-side visit too.
type Gtag = (...args: unknown[]) => void;
const gtag = (window as unknown as { gtag?: Gtag }).gtag;

if (gtag) {
    router.on('navigate', () => {
        gtag('event', 'page_view', {
            page_path: window.location.pathname + window.location.search,
            page_location: window.location.href,
            page_title: document.title,
        });
    });
}
