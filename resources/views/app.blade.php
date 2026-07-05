<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'dark') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Impact (affiliate) site ownership verification. --}}
        <meta name="impact-site-verification" value="b7419141-3520-4c15-ae56-a5ab57ca6acd">

        {{-- Google tag (gtag.js) --}}
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-Z5MK6HTM1D"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());

            gtag('config', 'G-Z5MK6HTM1D');
        </script>

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "dark" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(0.98 0 0);
            }

            html.dark {
                background-color: oklch(0.2046 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Impact (Target affiliate program) link-tracking + impression script --}}
        <script type="text/javascript">(function(i,m,p,a,c,t){c.ire_o=p;c[p]=c[p]||function(){(c[p].a=c[p].a||[]).push(arguments)};t=a.createElement(m);var z=a.getElementsByTagName(m)[0];t.async=1;t.src=i;z.parentNode.insertBefore(t,z)})('https://utt.impactcdn.com/P-A7404766-d1a2-4f10-b166-8a6757e16b5f1.js','script','impactStat',document,window);impactStat('transformLinks');impactStat('trackImpression');</script>

        @php
            $appName = config('app.name', 'CardFoo');
            // Per-page share meta (e.g. public collection/wishlist pages) overrides
            // the site defaults; falls back to the brand copy everywhere else.
            $pageMeta = $page['props']['meta'] ?? null;
            $description = $pageMeta['description'] ?? 'Value your trading cards and collectibles with confidence-scored market prices — sealed product, raw singles, and graded items. Wax on.';
            $ogTitle = $pageMeta['title'] ?? $appName.' — Wax on.';
            $pageTitle = $pageMeta['title'] ?? $appName.' — Trading card prices, values & collection tracker';
            // A page may supply its own share image (e.g. the card itself); fall
            // back to the branded banner. The banner is 1500×500; per-page images
            // (card scans) aren't, so only assert dimensions for the banner.
            $ogImage = $pageMeta['image'] ?? \Illuminate\Support\Facades\Storage::disk('s3')->url('phb/og/cardfoo-wax-on.jpg');
            $ogImageIsBanner = empty($pageMeta['image']);
            $ogType = $pageMeta['og_type'] ?? 'website';
            $twitterCard = $pageMeta['twitter_card'] ?? 'summary_large_image';
            $jsonLd = $pageMeta['jsonld'] ?? null;
            // Shareable/canonical URL. Path-only by default (SEO canonical); a page
            // may supply its own — browse/search pass the full URL so shared
            // searches keep their query. Synced client-side on navigation.
            $canonicalUrl = $pageMeta['url'] ?? url()->current();
        @endphp

        <meta name="description" content="{{ $description }}">
        <meta name="application-name" content="{{ $appName }}">
        <meta name="apple-mobile-web-app-title" content="{{ $appName }}">
        <meta name="theme-color" content="#111317">
        <link rel="canonical" href="{{ $canonicalUrl }}">

        <meta property="og:type" content="{{ $ogType }}">
        <meta property="og:site_name" content="{{ $appName }}">
        <meta property="og:title" content="{{ $ogTitle }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        <meta property="og:image" content="{{ $ogImage }}">
        @if($ogImageIsBanner)
        <meta property="og:image:width" content="1500">
        <meta property="og:image:height" content="500">
        @endif
        @isset($pageMeta['image_alt'])
        <meta property="og:image:alt" content="{{ $pageMeta['image_alt'] }}">
        @endisset
        @if($fbAppId = config('services.facebook.client_id'))
        <meta property="fb:app_id" content="{{ $fbAppId }}">
        @endif

        <meta name="twitter:card" content="{{ $twitterCard }}">
        <meta name="twitter:title" content="{{ $ogTitle }}">
        <meta name="twitter:description" content="{{ $description }}">
        <meta name="twitter:image" content="{{ $ogImage }}">

        @if($jsonLd)
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
        @endif

        {{-- Kaushan Script — brush font used for the "Wax on." slogan. --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Kaushan+Script&display=swap" rel="stylesheet">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ $pageTitle }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
