import { cn } from '@/lib/utils';

/**
 * A crosshair handle: open in the middle, so the point being placed is visible.
 *
 * The thing these are dragged onto is a border a pixel or two wide, and a solid
 * handle covers it. That is not a cosmetic problem — three points of centering
 * is about two pixels on a card this size, and roughly thirty points of score,
 * so a handle that hides the target costs more than any other part of this
 * tool. The centre is left clear and the arms stop short of it.
 *
 * Drawn rather than taken from the icon set, because the gap in the middle is
 * the whole design and no general-purpose glyph guarantees one.
 *
 * Two passes: a dark halo, then the light stroke over it. A card is white
 * border over dark artwork over bright foil, and a single-colour handle
 * disappears against one of those.
 */
export function Crosshair({
    tone,
    active,
    className,
}: {
    /** Which guide it belongs to, for the ring only. */
    tone?: 'outline' | 'frame';
    active?: boolean;
    className?: string;
}) {
    const arms = [
        // x1, y1, x2, y2 — each stopping well short of centre.
        [16, 3, 16, 10],
        [16, 22, 16, 29],
        [3, 16, 10, 16],
        [22, 16, 29, 16],
    ] as const;

    return (
        <svg
            viewBox="0 0 32 32"
            className={cn(
                'pointer-events-none transition-transform',
                active && 'scale-110',
                className,
            )}
            aria-hidden
        >
            <g
                strokeLinecap="round"
                fill="none"
                stroke="rgba(0,0,0,0.65)"
                strokeWidth={4}
            >
                {arms.map(([x1, y1, x2, y2]) => (
                    <line
                        key={`h-${x1}-${y1}`}
                        x1={x1}
                        y1={y1}
                        x2={x2}
                        y2={y2}
                    />
                ))}
                <circle cx={16} cy={16} r={5.5} />
            </g>

            <g
                strokeLinecap="round"
                fill="none"
                stroke="white"
                strokeWidth={1.75}
            >
                {arms.map(([x1, y1, x2, y2]) => (
                    <line
                        key={`w-${x1}-${y1}`}
                        x1={x1}
                        y1={y1}
                        x2={x2}
                        y2={y2}
                    />
                ))}
            </g>

            {/* The ring carries the guide's colour, so which quad a handle
                belongs to is still obvious without filling the middle. */}
            <circle
                cx={16}
                cy={16}
                r={5.5}
                fill="none"
                strokeWidth={1.75}
                stroke={tone === 'frame' ? '#f59e0b' : 'currentColor'}
                className={tone === 'frame' ? undefined : 'text-primary'}
            />
        </svg>
    );
}
