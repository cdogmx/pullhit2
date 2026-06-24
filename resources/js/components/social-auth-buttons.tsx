/**
 * "or" divider + social-login buttons for the login/register pages. These are
 * full-page navigations (OAuth redirect), so they use a plain anchor rather than
 * Inertia. Add more providers (e.g. Google) by dropping another anchor here.
 */
export function SocialAuthButtons() {
    return (
        <div className="flex flex-col gap-4">
            <div className="relative">
                <div className="absolute inset-0 flex items-center">
                    <span className="w-full border-t border-border" />
                </div>
                <div className="relative flex justify-center text-xs uppercase">
                    <span className="bg-background px-2 text-muted-foreground">
                        or
                    </span>
                </div>
            </div>

            <a
                href="/auth/facebook/redirect"
                className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-[#1877F2] px-4 text-sm font-medium text-white transition-colors hover:bg-[#166FE5]"
            >
                <FacebookIcon className="size-5" />
                Continue with Facebook
            </a>
        </div>
    );
}

function FacebookIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            className={className}
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="M24 12.073C24 5.404 18.627 0 12 0S0 5.404 0 12.073c0 6.026 4.388 11.02 10.125 11.927v-8.437H7.078v-3.49h3.047V9.41c0-3.017 1.792-4.683 4.533-4.683 1.313 0 2.686.235 2.686.235v2.97h-1.513c-1.49 0-1.955.93-1.955 1.886v2.265h3.328l-.532 3.49h-2.796v8.437C19.612 23.094 24 18.1 24 12.073z" />
        </svg>
    );
}
