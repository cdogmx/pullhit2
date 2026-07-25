import type { TargetsResponse } from '@/components/shared/list-target-picker';

// The user's wishlist options, loaded once and shared across every heart on the
// page (so a browse grid doesn't fetch per-tile) and the bulk action bar.
let cache: TargetsResponse | null = null;
let pending: Promise<TargetsResponse> | null = null;

export function loadWishlistTargets(): Promise<TargetsResponse> {
    if (cache) {
        return Promise.resolve(cache);
    }

    if (!pending) {
        pending = fetch('/wishlist/targets', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((d: TargetsResponse) => {
                cache = d;

                return d;
            });
    }

    return pending;
}

/** Call after creating a wishlist so the next reader sees it. */
export function clearWishlistTargets(): void {
    cache = null;
    pending = null;
}
