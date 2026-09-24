import { Move } from 'lucide-react';
import { useRef, useState } from 'react';
import { Crosshair } from '@/components/grading/crosshair';
import {
    ZOOM_MIN,
    ZoomControls,
    zoomStyle,
} from '@/components/grading/zoom-pane';
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

const CORNERS: { name: string; cursor: string }[] = [
    { name: 'top left', cursor: 'nwse-resize' },
    { name: 'top right', cursor: 'nesw-resize' },
    { name: 'bottom right', cursor: 'nwse-resize' },
    { name: 'bottom left', cursor: 'nesw-resize' },
];

/** Each side, as the pair of corners it joins. */
const SIDES: { name: string; from: number; to: number; cursor: string }[] = [
    { name: 'top', from: 0, to: 1, cursor: 'ns-resize' },
    { name: 'right', from: 1, to: 2, cursor: 'ew-resize' },
    { name: 'bottom', from: 2, to: 3, cursor: 'ns-resize' },
    { name: 'left', from: 3, to: 0, cursor: 'ew-resize' },
];

/**
 * A handle: a thin arrow saying which way it pulls, over a dark disc so it
 * stays legible on both a white border and dark artwork. Thin on purpose — a
 * chunky handle covers the edge it is being aligned to, which is the one pixel
 * that matters.
 */
/**
 * The handle is only a hit target now. It draws nothing itself — the crosshair
 * inside it does, and it is deliberately larger than what it draws so a border
 * this fine is still grabbable with a thumb.
 */
const HANDLE =
    'absolute grid -translate-x-1/2 -translate-y-1/2 place-items-center ' +
    'rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

const clamp = (v: number) => Math.min(1.2, Math.max(-0.2, v));

type Held =
    | { which: keyof Guides; kind: 'corner'; index: number }
    | { which: keyof Guides; kind: 'side'; index: number }
    | { which: keyof Guides; kind: 'whole' };

export type QuadTone = 'outline' | 'frame';

type Props = {
    src: string;
    guides: Guides;
    onChange: (guides: Guides) => void;
    alt: string;
    /**
     * Which quads to show. One, for framing the card; two, for measuring the
     * border between them. Same handles either way — a card is a quadrilateral
     * in both jobs, and learning one set of controls should be enough.
     */
    only?: QuadTone[];
    hint?: string;
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
export function GuideOverlay({
    src,
    guides,
    onChange,
    alt,
    only,
    hint,
}: Props) {
    const shown: QuadTone[] = only ?? ['outline', 'frame'];
    const boxRef = useRef<HTMLDivElement>(null);
    const [held, setHeld] = useState<Held | null>(null);
    const [zoom, setZoom] = useState(ZOOM_MIN);

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
        <div className="flex flex-col gap-2">
            <ZoomControls
                zoom={zoom}
                onZoom={setZoom}
                hint={hint ?? 'Zoom in to sit a corner exactly on the border.'}
            />

            <div className="max-h-[70vh] overflow-auto rounded border border-border">
                <div
                    ref={boxRef}
                    className="relative touch-none select-none"
                    style={zoomStyle(zoom)}
                    onPointerMove={move}
                    onPointerUp={() => setHeld(null)}
                    onPointerLeave={() => setHeld(null)}
                >
                    <img
                        src={src}
                        alt={alt}
                        className="pointer-events-none w-full"
                    />

                    <svg
                        className="pointer-events-none absolute inset-0 size-full"
                        viewBox="0 0 1 1"
                        preserveAspectRatio="none"
                    >
                        {shown.map((which) => (
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

                    {shown.map((which) => {
                        const quad = guides[which];
                        // The arrow is white on a dark disc; the tone marks which guide
                        // it belongs to through the ring, so the glyph stays legible.
                        const ring =
                            which === 'outline'
                                ? 'ring-primary'
                                : 'ring-amber-400';

                        return (
                            <div key={which}>
                                {/* The whole quad, for coarse placement before the
                            sides and corners are nudged into place. */}
                                <button
                                    type="button"
                                    aria-label={`Move the whole ${which}`}
                                    onPointerDown={(e) =>
                                        start({ which, kind: 'whole' }, e)
                                    }
                                    className={cn(
                                        HANDLE,
                                        ring,
                                        'size-7',
                                        held?.which === which &&
                                            held.kind === 'whole'
                                            ? 'cursor-grabbing'
                                            : 'cursor-grab',
                                    )}
                                    style={{
                                        left: `${centre(quad).x * 100}%`,
                                        top: `${centre(quad).y * 100}%`,
                                    }}
                                >
                                    <Move
                                        className="size-4"
                                        strokeWidth={1.5}
                                    />
                                </button>

                                {SIDES.map((side, i) => {
                                    const p = mid(quad, side);

                                    return (
                                        <button
                                            key={side.name}
                                            type="button"
                                            aria-label={`${which} ${side.name} side`}
                                            onPointerDown={(e) =>
                                                start(
                                                    {
                                                        which,
                                                        kind: 'side',
                                                        index: i,
                                                    },
                                                    e,
                                                )
                                            }
                                            className={cn(HANDLE, 'size-8')}
                                            style={{
                                                left: `${p.x * 100}%`,
                                                top: `${p.y * 100}%`,
                                                cursor: side.cursor,
                                            }}
                                        >
                                            <Crosshair
                                                tone={which}
                                                active={
                                                    held?.which === which &&
                                                    held.kind === 'side' &&
                                                    held.index === i
                                                }
                                                className="size-7"
                                            />
                                        </button>
                                    );
                                })}

                                {quad.map((p, i) => (
                                    <button
                                        key={i}
                                        type="button"
                                        aria-label={`${which} ${CORNERS[i].name} corner`}
                                        onPointerDown={(e) =>
                                            start(
                                                {
                                                    which,
                                                    kind: 'corner',
                                                    index: i,
                                                },
                                                e,
                                            )
                                        }
                                        className={cn(HANDLE, 'size-9')}
                                        style={{
                                            left: `${p.x * 100}%`,
                                            top: `${p.y * 100}%`,
                                            cursor: CORNERS[i].cursor,
                                        }}
                                    >
                                        <Crosshair
                                            tone={which}
                                            active={
                                                held?.which === which &&
                                                held.kind === 'corner' &&
                                                held.index === i
                                            }
                                            className="size-8"
                                        />
                                    </button>
                                ))}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
