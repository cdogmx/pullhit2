import {
    Move,
    MoveDiagonal,
    MoveDiagonal2,
    MoveHorizontal,
    MoveVertical,
} from 'lucide-react';
import { useRef, useState } from 'react';
import {
    ZOOM_MIN,
    ZoomControls,
    zoomStyle,
} from '@/components/grading/zoom-pane';
import { cn } from '@/lib/utils';

/** A crop as fractions of the image, so it applies to every frame alike. */
export type CropRect = { x: number; y: number; w: number; h: number };

/** The smallest crop worth keeping, as a fraction of the image. */
const MIN = 0.02;

type Handle =
    | 'nw'
    | 'n'
    | 'ne'
    | 'e'
    | 'se'
    | 's'
    | 'sw'
    | 'w'
    | 'move'
    | 'new';

/** Where each handle sits, which way it resizes, and the arrow that says so. */
const HANDLES: {
    id: Handle;
    x: number;
    y: number;
    cursor: string;
    Icon: typeof MoveVertical;
}[] = [
    { id: 'nw', x: 0, y: 0, cursor: 'nwse-resize', Icon: MoveDiagonal2 },
    { id: 'n', x: 0.5, y: 0, cursor: 'ns-resize', Icon: MoveVertical },
    { id: 'ne', x: 1, y: 0, cursor: 'nesw-resize', Icon: MoveDiagonal },
    { id: 'e', x: 1, y: 0.5, cursor: 'ew-resize', Icon: MoveHorizontal },
    { id: 'se', x: 1, y: 1, cursor: 'nwse-resize', Icon: MoveDiagonal2 },
    { id: 's', x: 0.5, y: 1, cursor: 'ns-resize', Icon: MoveVertical },
    { id: 'sw', x: 0, y: 1, cursor: 'nesw-resize', Icon: MoveDiagonal },
    { id: 'w', x: 0, y: 0.5, cursor: 'ew-resize', Icon: MoveHorizontal },
];

/**
 * A handle: a thin arrow saying which way it pulls, over a dark disc so it
 * stays legible against any photo. Thin on purpose — a chunky handle covers the
 * card edge it is being trimmed to, which is the one pixel that matters.
 */
const HANDLE =
    'absolute grid -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full ' +
    'bg-black/55 text-white ring-1 ring-primary backdrop-blur-[1px] ' +
    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

const clamp = (v: number) => Math.min(1, Math.max(0, v));

type Props = {
    src: string;
    crop: CropRect | null;
    onChange: (crop: CropRect | null) => void;
    alt: string;
};

/**
 * Draw a crop, then adjust it.
 *
 * A crop drawn in one drag is never quite right — the point of cropping here is
 * to cut the background away from the card's edge, which is a job of a few
 * pixels. So every side and corner stays grabbable afterwards, and the whole
 * box can be nudged, rather than the only correction being to draw it again.
 */
export function CropBox({ src, crop, onChange, alt }: Props) {
    const boxRef = useRef<HTMLDivElement>(null);
    const [held, setHeld] = useState<Handle | null>(null);
    const [zoom, setZoom] = useState(ZOOM_MIN);

    // What the box and the pointer were when the drag started. Resizing from
    // the deltas of the original — rather than accumulating each move — is what
    // keeps a handle under the cursor instead of drifting away from it.
    const origin = useRef<{ crop: CropRect; at: { x: number; y: number } }>(
        null!,
    );

    function pointAt(event: React.PointerEvent) {
        const box = boxRef.current!.getBoundingClientRect();

        return {
            x: clamp((event.clientX - box.left) / box.width),
            y: clamp((event.clientY - box.top) / box.height),
        };
    }

    function start(handle: Handle, event: React.PointerEvent) {
        event.stopPropagation();
        (event.target as Element).setPointerCapture?.(event.pointerId);

        const at = pointAt(event);
        origin.current = {
            crop: crop ?? { x: at.x, y: at.y, w: 0, h: 0 },
            at,
        };
        setHeld(handle);
    }

    function move(event: React.PointerEvent) {
        if (!held) {
            return;
        }

        const at = pointAt(event);
        const from = origin.current;

        if (held === 'new') {
            onChange({
                x: Math.min(from.at.x, at.x),
                y: Math.min(from.at.y, at.y),
                w: Math.abs(at.x - from.at.x),
                h: Math.abs(at.y - from.at.y),
            });

            return;
        }

        const c = from.crop;

        if (held === 'move') {
            // Moving clamps the position rather than the edges, so the box
            // keeps its size when it reaches the border instead of squashing.
            const dx = at.x - from.at.x;
            const dy = at.y - from.at.y;

            onChange({
                ...c,
                x: Math.min(1 - c.w, Math.max(0, c.x + dx)),
                y: Math.min(1 - c.h, Math.max(0, c.y + dy)),
            });

            return;
        }

        let { x, y, w, h } = c;

        if (held.includes('w')) {
            const right = c.x + c.w;
            x = Math.min(at.x, right - MIN);
            w = right - x;
        }

        if (held.includes('e')) {
            w = Math.max(MIN, at.x - c.x);
        }

        if (held.includes('n')) {
            const bottom = c.y + c.h;
            y = Math.min(at.y, bottom - MIN);
            h = bottom - y;
        }

        if (held.includes('s')) {
            h = Math.max(MIN, at.y - c.y);
        }

        onChange({ x, y, w: Math.min(w, 1 - x), h: Math.min(h, 1 - y) });
    }

    function end() {
        // A stray click is not a crop.
        if (held === 'new' && crop && (crop.w < MIN || crop.h < MIN)) {
            onChange(null);
        }

        setHeld(null);
    }

    return (
        <div className="flex flex-col gap-2">
            <ZoomControls
                zoom={zoom}
                onZoom={setZoom}
                hint="Zoom in to trim right up to the card's edge."
            />

            <div className="max-h-[70vh] overflow-auto rounded border border-border">
                <div
                    ref={boxRef}
                    className={cn(
                        'relative touch-none select-none',
                        crop ? 'cursor-default' : 'cursor-crosshair',
                    )}
                    style={zoomStyle(zoom)}
                    onPointerDown={(e) => {
                        // Starting a fresh drag outside an existing box replaces it.
                        if (!crop) {
                            start('new', e);
                        }
                    }}
                    onPointerMove={move}
                    onPointerUp={end}
                    onPointerLeave={end}
                >
                    <img
                        src={src}
                        alt={alt}
                        className="pointer-events-none w-full"
                    />

                    {crop && crop.w > 0 && crop.h > 0 && (
                        <>
                            {/* Dim what is being cut away, so the crop reads as a
                        selection rather than as a drawn rectangle. */}
                            <div className="pointer-events-none absolute inset-0 bg-black/50" />
                            <div
                                className="absolute overflow-hidden"
                                style={{
                                    left: `${crop.x * 100}%`,
                                    top: `${crop.y * 100}%`,
                                    width: `${crop.w * 100}%`,
                                    height: `${crop.h * 100}%`,
                                }}
                            >
                                <img
                                    src={src}
                                    alt=""
                                    aria-hidden
                                    className="pointer-events-none absolute max-w-none"
                                    style={{
                                        width: `${100 / crop.w}%`,
                                        left: `${(-crop.x / crop.w) * 100}%`,
                                        top: `${(-crop.y / crop.h) * 100}%`,
                                        height: `${100 / crop.h}%`,
                                    }}
                                />
                            </div>

                            <div
                                role="presentation"
                                onPointerDown={(e) => start('move', e)}
                                className={cn(
                                    'absolute border border-dashed border-primary',
                                    held === 'move'
                                        ? 'cursor-grabbing'
                                        : 'cursor-grab',
                                )}
                                style={{
                                    left: `${crop.x * 100}%`,
                                    top: `${crop.y * 100}%`,
                                    width: `${crop.w * 100}%`,
                                    height: `${crop.h * 100}%`,
                                }}
                            />

                            <button
                                type="button"
                                aria-label="Move the crop"
                                onPointerDown={(e) => start('move', e)}
                                className={cn(
                                    HANDLE,
                                    'size-7',
                                    held === 'move'
                                        ? 'cursor-grabbing'
                                        : 'cursor-grab',
                                )}
                                style={{
                                    left: `${(crop.x + crop.w / 2) * 100}%`,
                                    top: `${(crop.y + crop.h / 2) * 100}%`,
                                }}
                            >
                                <Move className="size-4" strokeWidth={1.5} />
                            </button>

                            {HANDLES.map((handle) => (
                                <button
                                    key={handle.id}
                                    type="button"
                                    aria-label={`Resize crop ${handle.id}`}
                                    onPointerDown={(e) => start(handle.id, e)}
                                    className={cn(
                                        HANDLE,
                                        'size-6',
                                        held === handle.id && 'scale-110',
                                    )}
                                    style={{
                                        left: `${(crop.x + crop.w * handle.x) * 100}%`,
                                        top: `${(crop.y + crop.h * handle.y) * 100}%`,
                                        cursor: handle.cursor,
                                    }}
                                >
                                    <handle.Icon
                                        className="size-3.5"
                                        strokeWidth={1.5}
                                    />
                                </button>
                            ))}
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
