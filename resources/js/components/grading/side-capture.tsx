import { Crop, RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** A crop as fractions of the image, so it applies to every frame alike. */
export type CropRect = { x: number; y: number; w: number; h: number };

export type Split = {
    left: string;
    right: string;
    top: string;
    bottom: string;
};

export const EMPTY_SPLIT: Split = {
    left: '',
    right: '',
    top: '',
    bottom: '',
};

const EDGES = ['left', 'right', 'top', 'bottom'] as const;

type Props = {
    side: string;
    files: File[];
    crop: CropRect | null;
    split: Split;
    onFiles: (files: File[]) => void;
    onCrop: (crop: CropRect | null) => void;
    onSplit: (edge: string, value: string) => void;
};

/**
 * One side's photos, its crop, and its centering numbers.
 *
 * The crop is stored as fractions and applied to every frame of the side
 * identically. That is not a shortcut — the warper differences frames against
 * each other, so cropping them differently would misalign the very thing the
 * surface read depends on.
 */
export function SideCapture({
    side,
    files,
    crop,
    split,
    onFiles,
    onCrop,
    onSplit,
}: Props) {
    const [dragging, setDragging] = useState<CropRect | null>(null);
    const boxRef = useRef<HTMLDivElement>(null);

    // Preview the first frame — the crop is judged on it and applied to all.
    // Derived rather than set from an effect, so the URL exists on the same
    // render as the files it came from.
    const preview = useMemo(
        () => (files.length > 0 ? URL.createObjectURL(files[0]) : null),
        [files],
    );

    // The object URL holds the file in memory until it is let go.
    useEffect(() => {
        if (!preview) {
            return;
        }

        return () => URL.revokeObjectURL(preview);
    }, [preview]);

    function pointIn(event: React.MouseEvent): { x: number; y: number } {
        const box = boxRef.current!.getBoundingClientRect();

        return {
            x: Math.min(1, Math.max(0, (event.clientX - box.left) / box.width)),
            y: Math.min(1, Math.max(0, (event.clientY - box.top) / box.height)),
        };
    }

    const shown = dragging ?? crop;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-sm capitalize">
                    {side}
                    {files.length > 0 && (
                        <Badge variant="secondary">
                            {files.length} photo{files.length === 1 ? '' : 's'}
                        </Badge>
                    )}
                    {crop && (
                        <Badge variant="outline" className="gap-1">
                            <Crop className="size-3" />
                            cropped
                        </Badge>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <Input
                    type="file"
                    accept="image/*"
                    multiple
                    aria-label={`${side} photos`}
                    onChange={(e) => {
                        onFiles(Array.from(e.target.files ?? []));
                        // A crop drawn on the old first frame means nothing on
                        // a new one.
                        onCrop(null);
                    }}
                />

                {files.length === 1 && (
                    <p className="rounded border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-xs text-amber-700 dark:text-amber-500">
                        One photo. Centering and the rectified card still work,
                        but{' '}
                        <strong>
                            surface cannot be read from a single image
                        </strong>{' '}
                        — the method compares frames against each other as the
                        glare moves, and one frame has nothing to compare
                        against. It will be reported as not assessed.
                    </p>
                )}

                {preview && (
                    <div className="flex flex-col gap-2">
                        <p className="text-xs text-muted-foreground">
                            Drag on the first frame to crop. The same crop is
                            applied to every photo of this side — cropping them
                            differently would misalign the frames.
                        </p>
                        <div
                            ref={boxRef}
                            className="relative w-full cursor-crosshair overflow-hidden rounded border border-border select-none"
                            onMouseDown={(e) => {
                                const p = pointIn(e);
                                setDragging({ x: p.x, y: p.y, w: 0, h: 0 });
                            }}
                            onMouseMove={(e) => {
                                if (!dragging) {
                                    return;
                                }

                                const p = pointIn(e);
                                setDragging((d) =>
                                    d
                                        ? {
                                              x: Math.min(d.x, p.x),
                                              y: Math.min(d.y, p.y),
                                              w: Math.abs(p.x - d.x),
                                              h: Math.abs(p.y - d.y),
                                          }
                                        : d,
                                );
                            }}
                            onMouseUp={() => {
                                // A stray click is not a crop.
                                if (
                                    dragging &&
                                    dragging.w > 0.02 &&
                                    dragging.h > 0.02
                                ) {
                                    onCrop(dragging);
                                }

                                setDragging(null);
                            }}
                            onMouseLeave={() => setDragging(null)}
                        >
                            <img
                                src={preview}
                                alt={`${side} first frame`}
                                className="pointer-events-none w-full"
                            />
                            {shown && shown.w > 0 && (
                                <div
                                    className="pointer-events-none absolute border-2 border-primary bg-primary/10"
                                    style={{
                                        left: `${shown.x * 100}%`,
                                        top: `${shown.y * 100}%`,
                                        width: `${shown.w * 100}%`,
                                        height: `${shown.h * 100}%`,
                                    }}
                                />
                            )}
                        </div>
                        {crop && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="self-start"
                                onClick={() => onCrop(null)}
                            >
                                <RotateCcw className="size-3.5" />
                                Clear crop
                            </Button>
                        )}
                    </div>
                )}

                <div>
                    <p className="mb-2 text-xs text-muted-foreground">
                        Centering, as a grading report writes it — TAG prints
                        this side as e.g. <code>46L/54R 47T/53B</code>. Nothing
                        detects it yet, and the two sides are cut differently,
                        so each is typed on its own.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        {EDGES.map((edge) => (
                            <div key={edge} className="flex flex-col gap-1.5">
                                <Label
                                    htmlFor={`${side}-${edge}`}
                                    className="text-xs capitalize"
                                >
                                    {edge}
                                </Label>
                                <Input
                                    id={`${side}-${edge}`}
                                    value={split[edge]}
                                    placeholder={
                                        edge === 'left' ? '46' : undefined
                                    }
                                    className="w-20"
                                    onChange={(e) =>
                                        onSplit(edge, e.target.value)
                                    }
                                />
                            </div>
                        ))}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * Apply a fractional crop to a file, as a new file.
 *
 * Done in the browser so the pipeline receives exactly what was framed on
 * screen, and so a 12-megapixel photo is not uploaded to have 80% of it thrown
 * away server-side.
 */
export async function cropFile(file: File, crop: CropRect): Promise<File> {
    const bitmap = await createImageBitmap(file);

    const sx = Math.round(crop.x * bitmap.width);
    const sy = Math.round(crop.y * bitmap.height);
    const sw = Math.max(1, Math.round(crop.w * bitmap.width));
    const sh = Math.max(1, Math.round(crop.h * bitmap.height));

    const canvas = document.createElement('canvas');
    canvas.width = sw;
    canvas.height = sh;

    const ctx = canvas.getContext('2d')!;
    ctx.drawImage(bitmap, sx, sy, sw, sh, 0, 0, sw, sh);
    bitmap.close();

    const blob = await new Promise<Blob | null>((resolve) =>
        // PNG, not JPEG: the surface read is looking for faint scratches, and
        // JPEG's ringing around edges is exactly the kind of artefact it would
        // mistake for one.
        canvas.toBlob(resolve, 'image/png'),
    );

    if (!blob) {
        return file;
    }

    return new File([blob], file.name.replace(/\.\w+$/, '') + '-crop.png', {
        type: 'image/png',
    });
}
