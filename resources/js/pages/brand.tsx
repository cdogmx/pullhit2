import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';

/**
 * Brand assets page — public showcase of the CardFoo mark and wordmark at
 * larger sizes, with usage variants, the brand palette, and downloadable
 * icon files. Chrome (header/footer) is provided by the AppShell layout.
 */
const BRAND_GOLD = '#CBB601';
const BELT_BLACK = '#111317';

const markSizes = [24, 32, 48, 64, 96];

const variants = [
    {
        label: 'Primary',
        bg: BRAND_GOLD,
        mark: BELT_BLACK,
        className: '',
    },
    {
        label: 'Inverse',
        bg: BELT_BLACK,
        mark: BRAND_GOLD,
        className: '',
    },
    {
        label: 'On light',
        bg: '#ffffff',
        mark: BELT_BLACK,
        className: '',
    },
];

const colors = [
    { name: 'Brand Gold', hex: BRAND_GOLD, note: 'Primary accent' },
    { name: 'Belt Black', hex: BELT_BLACK, note: 'Mark + dark surfaces' },
];

const downloads = [
    { label: 'SVG mark', href: '/favicon.svg', file: 'favicon.svg' },
    { label: 'Favicon (.ico)', href: '/favicon.ico', file: 'favicon.ico' },
    {
        label: 'App icon (180px PNG)',
        href: '/apple-touch-icon.png',
        file: 'apple-touch-icon.png',
    },
];

// Large transparent-background lockups (mark + wordmark) for each ink.
const logoPngs = [
    {
        label: 'Logo PNG — black',
        href: '/brand-assets/cardfoo-logo-black.png',
        file: 'cardfoo-logo-black.png',
        bg: '#ffffff',
    },
    {
        label: 'Logo PNG — white',
        href: '/brand-assets/cardfoo-logo-white.png',
        file: 'cardfoo-logo-white.png',
        bg: BELT_BLACK,
    },
    {
        label: 'Logo PNG — gold',
        href: '/brand-assets/cardfoo-logo-gold.png',
        file: 'cardfoo-logo-gold.png',
        bg: BELT_BLACK,
    },
];

export default function Brand() {
    return (
        <>
            <Head title="Brand assets" />

            {/* Hero lockup */}
            <section className="mx-auto w-full max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
                <p className="text-sm font-medium text-muted-foreground">
                    Brand assets
                </p>
                <div className="mt-8 flex flex-col items-start gap-6 sm:flex-row sm:items-center sm:gap-8">
                    <span
                        className="flex aspect-square size-24 shrink-0 items-center justify-center rounded-2xl sm:size-32"
                        style={{ backgroundColor: BRAND_GOLD, color: BELT_BLACK }}
                    >
                        <AppLogoIcon className="size-16 fill-current sm:size-20" />
                    </span>
                    <div>
                        <h1 className="text-5xl font-bold tracking-tight sm:text-6xl">
                            CardFoo
                            <span className="text-muted-foreground">.com</span>
                        </h1>
                        <p className="mt-3 font-script text-3xl text-primary">
                            Wax on.
                        </p>
                    </div>
                </div>
            </section>

            {/* The mark at multiple sizes */}
            <section className="border-t border-border bg-card/40">
                <div className="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                    <h2 className="text-sm font-semibold">The mark</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        A ninja mask — a tied headband over focused eyes.
                    </p>
                    <div className="mt-6 flex flex-wrap items-end gap-8">
                        {markSizes.map((px) => (
                            <div
                                key={px}
                                className="flex flex-col items-center gap-2"
                            >
                                <span
                                    className="flex items-center justify-center rounded-xl"
                                    style={{
                                        backgroundColor: BRAND_GOLD,
                                        color: BELT_BLACK,
                                        width: px * 1.6,
                                        height: px * 1.6,
                                    }}
                                >
                                    <AppLogoIcon
                                        className="fill-current"
                                        style={{ width: px, height: px }}
                                    />
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {px}px
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Usage variants */}
            <section className="border-t border-border">
                <div className="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                    <h2 className="text-sm font-semibold">Variants</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Gold on black is primary. Keep the mark monochrome —
                        never recolor the ninja outside these pairings.
                    </p>
                    <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {variants.map((v) => (
                            <div
                                key={v.label}
                                className="flex flex-col items-center justify-center gap-4 rounded-xl border border-border p-10"
                            >
                                <span
                                    className="flex aspect-square size-20 items-center justify-center rounded-2xl"
                                    style={{
                                        backgroundColor: v.bg,
                                        color: v.mark,
                                    }}
                                >
                                    <AppLogoIcon className="size-12 fill-current" />
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {v.label}
                                </span>
                            </div>
                        ))}
                        {/* Horizontal lockup */}
                        <div className="flex flex-col items-center justify-center gap-4 rounded-xl border border-border p-10">
                            <span className="flex items-center gap-2">
                                <span
                                    className="flex aspect-square size-9 items-center justify-center rounded-md"
                                    style={{
                                        backgroundColor: BRAND_GOLD,
                                        color: BELT_BLACK,
                                    }}
                                >
                                    <AppLogoIcon className="size-6 fill-current" />
                                </span>
                                <span className="text-xl font-semibold tracking-tight">
                                    CardFoo
                                </span>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                Horizontal lockup
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            {/* Logo lockups (large transparent PNGs) */}
            <section className="border-t border-border">
                <div className="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                    <h2 className="text-sm font-semibold">Logo (PNG)</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Full lockup, transparent background, 4571×756. Pick the
                        ink that contrasts with your background.
                    </p>
                    <div className="mt-6 grid gap-4 sm:grid-cols-3">
                        {logoPngs.map((item) => (
                            <div
                                key={item.href}
                                className="flex flex-col gap-3 rounded-xl border border-border p-4"
                            >
                                <div
                                    className="flex items-center justify-center rounded-lg p-6"
                                    style={{ backgroundColor: item.bg }}
                                >
                                    <img
                                        src={item.href}
                                        alt={item.label}
                                        className="h-10 w-auto"
                                    />
                                </div>
                                <Button asChild variant="outline" size="sm">
                                    <a href={item.href} download={item.file}>
                                        <Download className="size-4" />
                                        {item.label}
                                    </a>
                                </Button>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Color + downloads */}
            <section className="border-t border-border bg-card/40">
                <div className="mx-auto grid w-full max-w-7xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-2 lg:px-8">
                    <div>
                        <h2 className="text-sm font-semibold">Color</h2>
                        <div className="mt-4 space-y-4">
                            {colors.map((c) => (
                                <div
                                    key={c.hex}
                                    className="flex items-center gap-4"
                                >
                                    <span
                                        className="size-14 shrink-0 rounded-lg border border-border"
                                        style={{ backgroundColor: c.hex }}
                                    />
                                    <div>
                                        <p className="text-sm font-medium">
                                            {c.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {c.hex} · {c.note}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div>
                        <h2 className="text-sm font-semibold">Downloads</h2>
                        <div className="mt-4 flex flex-wrap gap-3">
                            {downloads.map((item) => (
                                <Button
                                    key={item.href}
                                    asChild
                                    variant="outline"
                                    size="sm"
                                >
                                    <a href={item.href} download={item.file}>
                                        <Download className="size-4" />
                                        {item.label}
                                    </a>
                                </Button>
                            ))}
                        </div>
                    </div>
                </div>
            </section>
        </>
    );
}
