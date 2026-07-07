import type { CapacitorConfig } from '@capacitor/cli';

/**
 * CardFoo native shell (iOS + Android) via Capacitor.
 *
 * The app is a server-rendered Inertia app, so the native WebView loads the live
 * Laravel site (`server.url`) and layers native capabilities on top (camera for
 * the scanner, status bar, splash). This is the standard pattern for Inertia /
 * server-driven SPAs — there is no static bundle to ship offline.
 *
 * Local dev: point at your machine, e.g.
 *   CAP_SERVER_URL=http://192.168.1.20:8000 npx cap run ios
 * `mobile/dist` is a throwaway splash shell that only shows if the server can't
 * be reached; the real UI comes from `server.url`.
 */
const config: CapacitorConfig = {
    appId: 'com.cardfoo.app',
    appName: 'CardFoo',
    webDir: 'mobile/dist',
    server: {
        url: process.env.CAP_SERVER_URL || 'https://cardfoo.com',
        // Production is HTTPS-only; allow cleartext only when pointed at a LAN dev host.
        cleartext: !!process.env.CAP_SERVER_URL,
        allowNavigation: ['cardfoo.com', '*.cardfoo.com'],
    },
    ios: {
        contentInset: 'always',
    },
    plugins: {
        SplashScreen: {
            launchShowDuration: 800,
            backgroundColor: '#111317',
        },
    },
};

export default config;
