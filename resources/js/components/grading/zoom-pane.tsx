import { Maximize2, Minus, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';

export const ZOOM_MIN = 1;
export const ZOOM_MAX = 8;
const STEP = 0.5;

/**
 * Magnification for a pane that holds a photo and things dragged onto it.
 *
 * The inner element is widened rather than transformed, so everything inside it
 * is still positioned as a percentage of the image and needs no adjusting: a
 * handle at 40% is at 40% whether the pane is 100% wide or 800%. Crucially the
 * pointer maths is unchanged too — it reads the inner element's own bounding
 * box, which the browser reports scrolled and scaled already.
 *
 * A transform would have been the other option and a worse one: it moves the
 * painted pixels without moving the layout box, so every coordinate read off
 * that box afterwards would need dividing by the scale, in three places, each
 * able to drift from the others.
 */
export function zoomStyle(zoom: number) {
    return { width: `${zoom * 100}%` };
}

export function ZoomControls({
    zoom,
    onZoom,
    hint,
}: {
    zoom: number;
    onZoom: (zoom: number) => void;
    hint?: string;
}) {
    const set = (next: number) =>
        onZoom(Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, next)));

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                aria-label="Zoom out"
                disabled={zoom <= ZOOM_MIN}
                onClick={() => set(zoom - STEP)}
            >
                <Minus className="size-3.5" />
            </Button>

            <input
                type="range"
                min={ZOOM_MIN}
                max={ZOOM_MAX}
                step={0.25}
                value={zoom}
                aria-label="Zoom"
                onChange={(e) => set(Number(e.target.value))}
                className="h-1.5 w-40 cursor-pointer accent-primary"
            />

            <Button
                type="button"
                variant="outline"
                size="sm"
                aria-label="Zoom in"
                disabled={zoom >= ZOOM_MAX}
                onClick={() => set(zoom + STEP)}
            >
                <Plus className="size-3.5" />
            </Button>

            <span className="w-12 text-xs text-muted-foreground tabular-nums">
                {zoom.toFixed(1)}×
            </span>

            {zoom > ZOOM_MIN && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => set(ZOOM_MIN)}
                >
                    <Maximize2 className="size-3.5" />
                    Fit
                </Button>
            )}

            {hint && (
                <span className="text-xs text-muted-foreground">{hint}</span>
            )}
        </div>
    );
}
