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
            case name === 'collection/public':
            case name === 'wishlist/public':
            case name.startsWith('catalog/'):
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
