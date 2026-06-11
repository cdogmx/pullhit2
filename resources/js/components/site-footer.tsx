import { Link } from '@inertiajs/react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { home } from '@/routes';

/**
 * Shared marketing/public chrome footer. Mirrors SiteHeader so public pages
 * carry identical chrome. Links are honest placeholders ('#') until their
 * sections ship in later phases.
 */
const footerNav: {
    heading: string;
    links: { title: string; href: string }[];
}[] = [
    {
        heading: 'Product',
        links: [
            { title: 'Catalog', href: '#' },
            { title: 'Valuation', href: '#' },
            { title: 'Marketplace', href: '#' },
        ],
    },
    {
        heading: 'Company',
        links: [
            { title: 'About', href: '#' },
            { title: 'Brand', href: '/brand' },
            { title: 'Contact', href: '#' },
        ],
    },
    {
        heading: 'Legal',
        links: [
            { title: 'Privacy', href: '#' },
            { title: 'Terms', href: '#' },
        ],
    },
];

const appearanceOptions: {
    value: Appearance;
    icon: LucideIcon;
    label: string;
}[] = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'system', icon: Monitor, label: 'System' },
    { value: 'dark', icon: Moon, label: 'Dark' },
];

export function SiteFooter() {
    const { appearance, updateAppearance } = useAppearance();
    const year = new Date().getFullYear();

    return (
        <footer className="border-t border-border bg-background">
            <div className="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div className="grid gap-10 md:grid-cols-[1.5fr_repeat(3,1fr)]">
                    <div className="space-y-3">
                        <Link
                            href={home()}
                            className="flex items-center gap-2"
                            aria-label="Home"
                        >
                            <span className="flex aspect-square size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                                <AppLogoIcon className="size-5 fill-current" />
                            </span>
                            <span className="text-base font-semibold tracking-tight">
                                CardFoo
                            </span>
                        </Link>
                        <p className="max-w-xs text-sm text-muted-foreground">
                            <span className="font-script text-base text-foreground">
                                Wax on.
                            </span>{' '}
                            Confidence-scored market values for sealed product,
                            raw singles, and graded collectibles.
                        </p>
                    </div>

                    {footerNav.map((group) => (
                        <div key={group.heading}>
                            <h3 className="text-sm font-semibold">
                                {group.heading}
                            </h3>
                            <ul className="mt-3 space-y-2">
                                {group.links.map((link) => (
                                    <li key={link.title}>
                                        <a
                                            href={link.href}
                                            className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            {link.title}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>

                <div className="mt-10 flex flex-col items-center justify-between gap-4 border-t border-border pt-6 sm:flex-row">
                    <p className="text-sm text-muted-foreground">
                        &copy; {year} CardFoo. All rights reserved.
                    </p>
                    <div className="inline-flex items-center gap-1 rounded-lg border border-border p-1">
                        {appearanceOptions.map((option) => {
                            const Icon = option.icon;
                            const isActive = appearance === option.value;

                            return (
                                <Button
                                    key={option.value}
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`${option.label} theme`}
                                    aria-pressed={isActive}
                                    onClick={() =>
                                        updateAppearance(option.value)
                                    }
                                    className={cn(
                                        'size-8 rounded-md',
                                        isActive &&
                                            'bg-accent text-accent-foreground',
                                    )}
                                >
                                    <Icon className="size-4" />
                                </Button>
                            );
                        })}
                    </div>
                </div>
            </div>
        </footer>
    );
}
