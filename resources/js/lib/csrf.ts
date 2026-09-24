/**
 * The CSRF token from the page's meta tag.
 *
 * Inertia's own requests carry this automatically; a hand-written fetch does
 * not, and Laravel answers one without it with a 419 that looks nothing like a
 * validation error. Three places were about to read the same meta tag, so it
 * lives here once.
 */
export function csrf(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}
