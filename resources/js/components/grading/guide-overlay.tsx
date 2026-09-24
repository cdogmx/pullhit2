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

const CORNERS = ['top left', 'top right', 'bottom right', 'bottom left'];

/** Each side, as the pair of corners it joins. */
const SIDES: { name: string; from: number; to: number; cursor: string }[] = [
    { name: 'top', from: 0, to: 1, cursor: 'ns-resize' },
    { name: 'right', from: 1, to: 2, cursor: 'ew-resize' },
    { name: 'bottom', from: 2, to: 3, cursor: 'ns-resize' },
    { name: 'left', from: 3, to: 0, cursor: 'ew-resize' },
];

const clamp = (v: number) => Math.min(1.2, Math.max(-0.2, v));

type Held =
    | { which: keyof Guides; kind: 'corner'; index: number }
    | { which: keyof Guides; kind: 'side'; index: number }
    | { which: keyof Guides; kind: 'whole' };

type Props = {
    src: string;
    guides: Guides;
    onChange: (guides: Guides) => void;
    alt: string;
};

/**
 * Two adjustable quads over a photo: the card's outer edge, and the inner edge
 * of its border where the artwork starts.
 *
 * Centering is the one grading attribute that is measurement rather than
 * inference, and it is measured between exactly those two rectangles. Nothing
 * in the pipeline finds the inner one — card designs vary far too much for a
 * design-agnostic edge detector — so a person places it, which is what this is.
 *
 * Corners AND sides, because placing a border by corners alone is fighting the
 * tool: the usual correction is "this whole edge is a hair off", and that is one
 * drag of a side rather than two careful drags of corners. Dragging a side
 * translates the two corners it joins, which keeps the quad a quad — a card
 * photographed by hand is not a rectangle, and squaring it off here would throw
 * away the perspective the homography needs.
 */
export function GuideOverlay({ src, guides, onChange, alt }: Props) {
    const boxRef = useRef<HTMLDivElement>(null);
    const [held, setHeld] = useState<Held | null>(null);

    // The quad and the pointer as the drag began. Working from the original's
    // deltas rather than accumulating each move is what keeps the grabbed part
    // under the cursor instead of drifting away from it.
    const origin = useRef<{ quad: Quad; at: Point }>(null!);

    function pointAt(event: React.PointerEvent): Point {
        const box = boxRef.current!.getBoundingClientRect();

        return {
            x: clamp((event.clientX - box.left) / box.width),
            y: clamp((event.clientY - box.top) / box.height),
        };
    }

    function start(what: Held, event: React.PointerEvent) {
        event.stopPropagation();
        (event.target as Element).setPointerCapture?.(event.pointerId);

        origin.current = { quad: guides[what.which], at: pointAt(event) };
        setHeld(what);
    }

    function move(event: React.PointerEvent) {
        if (!held) {
            return;
        }

        const at = pointAt(event);
        const from = origin.current;
        const quad = [...from.quad] as Quad;

        if (held.kind === 'corner') {
            quad[held.index] = at;
        } else {
            const dx = at.x - from.at.x;
            const dy = at.y - from.at.y;

            const moving =
                held.kind === 'whole'
                    ? [0, 1, 2, 3]
                    : [SIDES[held.index].from, SIDES[held.index].to];

            for (const i of moving) {
                quad[i] = {
                    x: clamp(from.quad[i].x + dx),
                    y: clamp(from.quad[i].y + dy),
                };
            }
        }

        onChange({ ...guides, [held.which]: quad });
    }

    const mid = (q: Quad, side: (typeof SIDES)[number]): Point => ({
        x: (q[side.from].x + q[side.to].x) / 2,
        y: (q[side.from].y + q[side.to].y) / 2,
    });

    const centre = (q: Quad): Point => ({
        x: q.reduce((t, p) => t + p.x, 0) / 4,
        y: q.reduce((t, p) => t + p.y, 0) / 4,
    });

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
                        strokeWidth={1.5}
                        vectorEffect="non-scaling-stroke"
                    />
                ))}
            </svg>

            {(['outline', 'frame'] as const).map((which) => {
                const quad = guides[which];
                const tone =
                    which === 'outline' ? 'bg-primary' : 'bg-amber-500';

                return (
                    <div key={which}>
                        {/* The whole quad, for coarse placement before the
                            edges are nudged into place. */}
                        <button
                            type="button"
                            aria-label={`Move the ${which}`}
                            onPointerDown={(e) =>
                                start({ which, kind: 'whole' }, e)
                            }
                            className={cn(
                                'absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white opacity-70 shadow',
                                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                tone,
                                held?.which === which && held.kind === 'whole'
                                    ? 'cursor-grabbing'
                                    : 'cursor-grab',
                            )}
                            style={{
                                left: `${centre(quad).x * 100}%`,
                                top: `${centre(quad).y * 100}%`,
                            }}
                        />

                        {SIDES.map((side, i) => {
                            const p = mid(quad, side);

                            return (
                                <button
                                    key={side.name}
                                    type="button"
                                    aria-label={`${which} ${side.name} side`}
                                    onPointerDown={(e) =>
                                        start(
                                            { which, kind: 'side', index: i },
                                            e,
                                        )
                                    }
                                    className={cn(
                                        'absolute h-2.5 w-6 -translate-x-1/2 -translate-y-1/2 rounded-sm border border-white shadow',
                                        'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        tone,
                                        held?.which === which &&
                                            held.kind === 'side' &&
                                            held.index === i &&
                                            'scale-125',
                                    )}
                                    style={{
                                        left: `${p.x * 100}%`,
                                        top: `${p.y * 100}%`,
                                        cursor: side.cursor,
                                        // Left and right read better upright.
                                        transform:
                                            side.name === 'left' ||
                                            side.name === 'right'
                                                ? 'translate(-50%, -50%) rotate(90deg)'
                                                : undefined,
                                    }}
                                />
                            );
                        })}

                        {quad.map((p, i) => (
                            <button
                                key={i}
                                type="button"
                                aria-label={`${which} ${CORNERS[i]} corner`}
                                onPointerDown={(e) =>
                                    start(
                                        { which, kind: 'corner', index: i },
                                        e,
                                    )
                                }
                                className={cn(
                                    'absolute size-4 -translate-x-1/2 -translate-y-1/2 cursor-grab rounded-full border-2 border-white shadow',
                                    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    tone,
                                    held?.which === which &&
                                        held.kind === 'corner' &&
                                        held.index === i &&
                                        'scale-125 cursor-grabbing',
                                )}
                                style={{
                                    left: `${p.x * 100}%`,
                                    top: `${p.y * 100}%`,
                                }}
                            />
                        ))}
                    </div>
                );
            })}
        </div>
    );
}
