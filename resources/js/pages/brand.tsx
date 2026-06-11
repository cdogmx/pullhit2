import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';

/**
 * Brand assets page — public showcase of the CardFoo mark and wordmark at
 * larger sizes, with usage variants and downloadable icon files. Chrome
 * (header/footer) is provided by the AppShell layout.
 */
const BELT_BLACK = '#111317';

const markSizes = [
    { px: 24, label: '24px' },
    { px: 32, label: '32px' },
    { px: 48, label: '48px' },
    { px: 64, label: '64px' },
    { px: 96, label: '96px' },
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
                        className="flex aspect-square size-24 shrink-0 items-center justify-center rounded-2xl text-white sm:size-32"
                        style={{ backgroundColor: BELT_BLACK }}
                    >
                        <AppLogoIcon className="size-16 fill-current sm:size-20" />
                    </span>
                    <div>
                        <h1 className="text-5xl font-bold tracking-tight sm:text-6xl">
                            CardFoo<span className="text-muted-foreground">.com</span>
                        </h1>
                        <p className="mt-3 text-lg text-muted-foreground">
                            Become a black belt in collecting.
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
                        {markSizes.map((size) => (
                            <div
                                key={size.px}
                                className="flex flex-col items-center gap-2"
                            >
                                <span
                                    className="flex items-center justify-center rounded-xl text-white"
                                    style={{
                                        backgroundColor: BELT_BLACK,
                                        width: size.px * 1.6,
                                        height: size.px * 1.6,
                                    }}
                                >
                                    <AppLogoIcon
                                        className="fill-current"
                                        style={{
                                            width: size.px,
                                            height: size.px,
                                        }}
                                    />
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {size.label}
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
                    <div className="mt-6 grid gap-4 sm:grid-cols-3">
                        {/* On belt black */}
                        <div className="flex flex-col items-center justify-center gap-4 rounded-xl border border-border p-10">
                            <span
                                className="flex aspect-square size-20 items-center justify-center rounded-2xl text-white"
                                style={{ backgroundColor: BELT_BLACK }}
                            >
                                <AppLogoIcon className="size-12 fill-current" />
                            </span>
                            <span className="text-xs text-muted-foreground">
                                On belt black
                            </span>
                        </div>
                        {/* On white */}
                        <div className="flex flex-col items-center justify-center gap-4 rounded-xl border border-border bg-white p-10">
                            <span className="flex aspect-square size-20 items-center justify-center text-neutral-900">
                                <AppLogoIcon className="size-12 fill-current" />
                            </span>
                            <span className="text-xs text-neutral-500">
                                On white
                            </span>
                        </div>
                        {/* Mark + wordmark */}
                        <div className="flex flex-col items-center justify-center gap-4 rounded-xl border border-border p-10">
                            <span className="flex items-center gap-2">
                                <span
                                    className="flex aspect-square size-9 items-center justify-center rounded-md text-white"
                                    style={{ backgroundColor: BELT_BLACK }}
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

            {/* Color + downloads */}
            <section className="border-t border-border bg-card/40">
                <div className="mx-auto grid w-full max-w-7xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-2 lg:px-8">
                    <div>
                        <h2 className="text-sm font-semibold">Color</h2>
                        <div className="mt-4 flex items-center gap-4">
                            <span
                                className="size-14 rounded-lg border border-border"
                                style={{ backgroundColor: BELT_BLACK }}
                            />
                            <div>
                                <p className="text-sm font-medium">Belt Black</p>
                                <p className="text-sm text-muted-foreground">
                                    {BELT_BLACK}
                                </p>
                            </div>
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
