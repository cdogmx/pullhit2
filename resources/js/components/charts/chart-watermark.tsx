import AppLogoIcon from '@/components/app-logo-icon';

/**
 * A subtle, centered CardFoo brand lockup sitting BEHIND a chart's plot — the
 * same mark-in-a-gold-badge + wordmark used in the site header, scaled up and
 * faded so it brands screenshots/shares without competing with the data. Uses
 * theme tokens (bg-primary / currentColor) so it reads in light and dark,
 * pointer-transparent so tooltips still work, and aria-hidden (decorative).
 * Drop it as the first child of a `relative` chart container, with the chart
 * layered above via `relative z-10`.
 */
export function ChartWatermark() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 z-0 flex select-none items-center justify-center gap-2.5 opacity-[0.08]"
        >
            <span className="flex aspect-square size-14 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                <AppLogoIcon className="size-9 fill-current" />
            </span>
            <span className="text-3xl font-semibold tracking-tight text-foreground">
                CardFoo
            </span>
        </div>
    );
}
