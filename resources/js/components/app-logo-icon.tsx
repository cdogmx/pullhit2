import type { SVGAttributes } from 'react';

/**
 * CardFoo brand mark — a ninja mask: a tied headband with trailing knot
 * ribbons over a pair of focused eyes. Single-color (inherits `currentColor`
 * via `fill-current`) so it reads on the brand square in light or dark mode.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
            {/* Headband */}
            <path d="M4 12.5 L25 13.6 L25 16 L4 14.9 Z" />
            {/* Knot ribbons */}
            <path d="M24 12.9 L30.5 10 L30.5 12.4 L25 15 Z" />
            <path d="M24.5 15 L30 15.4 L29.4 17.6 L25 16.2 Z" />
            {/* Eyes */}
            <path d="M7 17.9 L14.5 19 L14.5 21 L7 20.1 Z" />
            <path d="M25 17.9 L17.5 19 L17.5 21 L25 20.1 Z" />
        </svg>
    );
}
