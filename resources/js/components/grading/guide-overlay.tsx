import { useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export type Point = { x: number; y: number };

/** Four corners, clockwise from the top left — the order a homography wants. */
export type Quad = [Point, Point, Point, Point];

export type Guides = { outline: Quad; frame: Quad };

/** A quad inset from the image edge by a fraction, clockwise from top left. */
export function insetQuad(inset: number): Quad {
    const a = inset;
    const b = 1 - inset;

    return [
        { x: a, y: a },
        { x: b, y: a },
        { x: b, y: b },
        { x: a, y: b },
    ];
}

export const DEFAULT_GUIDES: Guides = {
    outline: insetQuad(0.08),
    frame: insetQuad(0.16),
};

const LABELS = ['top left', 'top right', 'bottom right', 'bottom left'];

type Props = {
    src: string;
    guides: Guides;
    onChange: (guides: Guides) => void;
    alt: string;
};

/**
 * Two draggable quads over a photo: the card's outline, and its artwork frame.
 *
 * Centering is the one grading attribute that is measurement rather than
 * inference, and it needs exactly these two rectangles. Nothing in the pipeline
 * finds the inner one — card designs vary far too much for a design-agnostic
 * edge detector — so a person marks it, which is what this is for.
 *
 * Corners rather than edges, because a card photographed by hand is a
 * quadrilateral, not a rectangle. The homography that flattens the card is
 * fitted to these four points, so the margins end up measured on the card
 * rather than in the photograph.
 */
export function GuideOverlay({ src, guides, onChange, alt }: Props) {
    const boxRef = useRef<HTMLDivElement>(null);
    const [held, setHeld] = useState<{
        which: keyof Guides;
        index: number;
    } | null>(null);

    function pointAt(event: React.PointerEvent): Point {
        const box = boxRef.current!.getBoundingClientRect();

        return {
            x: Math.min(1, Math.max(0, (event.clientX - box.left) / box.width)),
            y: Math.min(1, Math.max(0, (event.clientY - box.top) / box.height)),
        };
    }

    function move(event: React.PointerEvent) {
        if (!held) {
            return;
        }

        const next = pointAt(event);
        const quad = [...guides[held.which]] as Quad;
        quad[held.index] = next;

        onChange({ ...guides, [held.which]: quad });
    }

    return (
        <div
            ref={boxRef}
            className="relative w-full touch-none overflow-hidden rounded border border-border select-none"
            onPointerMove={move}
            onPointerUp={() => setHeld(null)}
            onPointerLeave={() => setHeld(null)}
        >
            <img src={src} alt={alt} className="pointer-events-none w-full" />

            <svg
                className="pointer-events-none absolute inset-0 size-full"
                viewBox="0 0 1 1"
                preserveAspectRatio="none"
            >
                {(['outline', 'frame'] as const).map((which) => (
                    <polygon
                        key={which}
                        points={guides[which]
                            .map((p) => `${p.x},${p.y}`)
                            .join(' ')}
                        className={cn(
                            'fill-none',
                            which === 'outline'
                                ? 'stroke-primary'
                                : 'stroke-amber-500',
                        )}
                        // Scales with the viewBox, so it stays hairline-thin at
                        // any rendered size.
                        strokeWidth={0.004}
                        vectorEffect="non-scaling-stroke"
                    />
                ))}
            </svg>

            {(['outline', 'frame'] as const).map((which) =>
                guides[which].map((p, i) => (
                    <button
                        key={`${which}-${i}`}
                        type="button"
                        aria-label={`${which} ${LABELS[i]}`}
                        onPointerDown={(e) => {
                            e.currentTarget.setPointerCapture(e.pointerId);
                            setHeld({ which, index: i });
                        }}
                        className={cn(
                            'absolute size-4 -translate-x-1/2 -translate-y-1/2 cursor-grab rounded-full border-2 border-white shadow',
                            'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            which === 'outline' ? 'bg-primary' : 'bg-amber-500',
                            held?.which === which &&
                                held.index === i &&
                                'scale-125 cursor-grabbing',
                        )}
                        style={{
                            left: `${p.x * 100}%`,
                            top: `${p.y * 100}%`,
                        }}
                    />
                )),
            )}
        </div>
    );
}
