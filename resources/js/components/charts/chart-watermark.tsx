import AppLogoIcon from '@/components/app-logo-icon';

/**
 * A subtle, centered CardFoo brand mark sitting BEHIND a chart's plot — brands
 * screenshots/shares without competing with the data. Uses the inline SVG mark
 * (currentColor) so it reads in light and dark themes, is pointer-transparent so
 * tooltips still work, and is aria-hidden (purely decorative). Drop it as the
 * first child of a `relative` chart container, with the chart itself layered
 * above via `relative z-10`.
 */
export function ChartWatermark() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 z-0 flex select-none flex-col items-center justify-center gap-1 opacity-[0.055]"
        >
            <AppLogoIcon className="w-[22%] max-w-24 min-w-12 fill-current text-foreground" />
            <span className="text-lg leading-none font-bold tracking-tight text-foreground">
                CardFoo
            </span>
        </div>
    );
}
