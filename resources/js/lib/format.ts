/** Format integer minor units (cents) as currency; "—" when absent. */
export function formatMoney(
    cents: number | null | undefined,
    currency = 'USD',
): string {
    if (cents == null) {
        return '—';
    }

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        minimumFractionDigits: cents % 100 === 0 ? 0 : 2,
        maximumFractionDigits: 2,
    }).format(cents / 100);
}

/** Compact "as of" age, e.g. "today", "4d ago", "3mo ago". */
export function relativeTime(iso?: string | null): string {
    if (!iso) {
        return '';
    }

    const days = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 86_400_000),
    );

    if (days === 0) {
        return 'today';
    }

    if (days === 1) {
        return '1d ago';
    }

    if (days < 30) {
        return `${days}d ago`;
    }

    const months = Math.round(days / 30);

    return months < 12 ? `${months}mo ago` : `${Math.round(months / 12)}y ago`;
}

/** Theme-token Badge variant for a confidence label. */
export function confidenceVariant(
    label: string,
): 'default' | 'secondary' | 'outline' {
    if (label === 'High') {
        return 'default';
    }

    if (label === 'Medium') {
        return 'secondary';
    }

    return 'outline';
}

/**
 * Tailwind text-color class for the confidence dot, by label. Pairs with
 * `bg-current` so a thin-market (Low) value is visibly distinct from a trusted
 * one in compact list tiles — the differentiator vs a bare number.
 */
export function confidenceDotClass(label: string): string {
    switch (label) {
        case 'High':
            return 'text-emerald-500';
        case 'Medium':
            return 'text-amber-500';
        default:
            return 'text-muted-foreground';
    }
}

/** Signed percent trend, e.g. "↑1.9%" / "↓3.0%"; "" when null. */
export function formatTrend(pct: number | null | undefined): string {
    if (pct == null || pct === 0) {
        return '';
    }

    return pct > 0 ? `↑${pct}%` : `↓${Math.abs(pct)}%`;
}
