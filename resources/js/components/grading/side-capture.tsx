import {
    Check,
    Images,
    RotateCcw,
    Ruler,
    ScanSearch,
    Sparkles,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { CropBox } from '@/components/grading/crop-box';
import type { CropRect } from '@/components/grading/crop-box';
import {
    DEFAULT_GUIDES,
    GuideOverlay,
} from '@/components/grading/guide-overlay';
import type { Guides, Quad } from '@/components/grading/guide-overlay';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { csrf } from '@/lib/csrf';
import { cn } from '@/lib/utils';

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

const STEPS = ['Photos', 'Crop', 'Guides'] as const;

type Props = {
    side: string;
    files: File[];
    crop: CropRect | null;
    split: Split;
    guides: Guides | null;
    onFiles: (files: File[]) => void;
    onCrop: (crop: CropRect | null) => void;
    onSplit: (edge: string, value: string) => void;
    onGuides: (guides: Guides | null) => void;
    onProposed: (guides: Guides) => void;
};

/**
 * One side, in the order the work actually happens: photos, then crop, then
 * guides.
 *
 * Stepped because the steps are not independent. A card photographed on a white
 * background is a small card in a large frame, and asking either a person or a
 * model to put a guide on "the card's outer edge" at that framing invites the
 * answer "somewhere near the edge of the picture" — which is what the first
 * real test produced. Crop first and the card fills the frame, so its edge is
 * where the eye already is.
 *
 * The guides are then placed on the CROPPED image, which is also the image the
 * server receives. That removes a whole class of bug: guides drawn on one
 * picture and applied to another had to be mapped between the two, and a
 * mapping that exists only to reconcile two views is a mapping that can be
 * wrong.
 */
export function SideCapture({
    side,
    files,
    crop,
    split,
    guides,
    onFiles,
    onCrop,
    onSplit,
    onGuides,
    onProposed,
}: Props) {
    const [step, setStep] = useState(0);
    const [asking, setAsking] = useState(false);
    const [finding, setFinding] = useState(false);
    const [proposeError, setProposeError] = useState<string | null>(null);

    // The card's outer edge as the detector found it, in the ORIGINAL photo's
    // coordinates. Kept so the outer guide can start on the real edge instead
    // of on an arbitrary inset rectangle.
    const [detected, setDetected] = useState<Quad | null>(null);
    const [cropped, setCropped] = useState<{
        file: File;
        url: string;
    } | null>(null);

    // The first frame, which the crop is judged on. Derived rather than set
    // from an effect, so the URL exists on the same render as its file.
    const original = useMemo(
        () => (files.length > 0 ? URL.createObjectURL(files[0]) : null),
        [files],
    );

    useEffect(() => {
        if (!original) {
            return;
        }

        return () => URL.revokeObjectURL(original);
    }, [original]);

    // The cropped first frame — what the guides are placed on, and what the
    // model is shown. Built here rather than at submit time so that what is
    // being looked at and what is being measured are the same picture.
    useEffect(() => {
        if (!crop || files.length === 0) {
            return;
        }

        let stale = false;
        let url: string | null = null;

        void (async () => {
            const { cropFile } = await import('@/lib/crop-image');
            const file = await cropFile(files[0], crop);

            if (stale) {
                return;
            }

            url = URL.createObjectURL(file);
            setCropped({ file, url });
        })();

        return () => {
            stale = true;

            if (url) {
                URL.revokeObjectURL(url);
            }
        };
    }, [crop, files]);

    // With a crop set, every later step works on the cropped picture.
    const working = crop && cropped ? cropped.url : original;
    const workingFile = crop && cropped ? cropped.file : files[0];

    /**
     * Find the card and crop to it.
     *
     * The pipeline's own detector, not a model: Otsu threshold, largest quad.
     * On the case this tool is for — a card on a plain background — it beats
     * anything that has to be asked in words, it is deterministic, and it costs
     * nothing. Run on upload so the usual answer is already there.
     */
    async function findCard(file: File) {
        setFinding(true);

        try {
            const body = new FormData();
            body.append('photo', file);

            const response = await fetch('/admin/grade-predictor/detect', {
                method: 'POST',
                body,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            });

            if (!response.ok) {
                // Not an error worth showing: the crop box is right there.
                return;
            }

            const payload = await response.json();
            setDetected(payload.outline as Quad);
            onCrop(payload.crop);
        } catch {
            // Same — a failed detection just means cropping by hand.
        } finally {
            setFinding(false);
        }
    }

    /**
     * The outer guide, put where the detector said the card is.
     *
     * Mapped from the original photo into the cropped one, which is the picture
     * the guides live on. The inner guide has no detector — card designs vary
     * far too much — so it starts as an inset of the outer one, which is at
     * least the right shape to drag.
     */
    function startingGuides(): Guides {
        if (!detected || !crop) {
            return DEFAULT_GUIDES;
        }

        const outline = detected.map((p) => ({
            x: (p.x - crop.x) / crop.w,
            y: (p.y - crop.y) / crop.h,
        })) as Quad;

        const centre = {
            x: outline.reduce((t, p) => t + p.x, 0) / 4,
            y: outline.reduce((t, p) => t + p.y, 0) / 4,
        };

        const frame = outline.map((p) => ({
            x: centre.x + (p.x - centre.x) * 0.88,
            y: centre.y + (p.y - centre.y) * 0.88,
        })) as Quad;

        return { outline, frame };
    }

    /**
     * Ask the model where the card and its border are.
     *
     * Shown the cropped card on purpose. Given a card adrift in a white field
     * it answers with something close to the frame, which is what the first
     * real test produced; given a card that fills the frame, "the outer edge"
     * is unambiguous.
     *
     * Still a starting point and never a measurement: it lands close and is
     * routinely a percent or two out, which at 9.06 points per percentage point
     * is twenty score points. A saved run records whether anybody moved them.
     */
    async function propose() {
        if (!workingFile) {
            return;
        }

        setAsking(true);
        setProposeError(null);

        try {
            const body = new FormData();
            body.append('photo', workingFile);

            const response = await fetch('/admin/grade-predictor/guides', {
                method: 'POST',
                body,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            });

            const payload = await response.json();

            if (!response.ok) {
                setProposeError(payload.message ?? 'That did not work.');

                return;
            }

            onProposed(payload);
        } catch {
            setProposeError('The request failed before it reached the model.');
        } finally {
            setAsking(false);
        }
    }

    const done = [files.length > 0, crop !== null, guides !== null];

    return (
        <Card>
            <CardHeader className="gap-3">
                <CardTitle className="flex items-center gap-2 text-sm capitalize">
                    {side}
                    {files.length > 0 && (
                        <Badge variant="secondary">
                            {files.length} photo{files.length === 1 ? '' : 's'}
                        </Badge>
                    )}
                </CardTitle>

                <div className="flex gap-1">
                    {STEPS.map((name, i) => (
                        <button
                            key={name}
                            type="button"
                            onClick={() => setStep(i)}
                            disabled={i > 0 && files.length === 0}
                            className={cn(
                                'flex flex-1 items-center justify-center gap-1.5 rounded-md border px-2 py-1.5 text-xs',
                                'disabled:cursor-not-allowed disabled:opacity-50',
                                step === i
                                    ? 'border-primary bg-primary/10 font-medium'
                                    : 'border-border hover:bg-muted',
                            )}
                        >
                            {done[i] ? (
                                <Check className="size-3.5 text-[#047857] dark:text-emerald-400" />
                            ) : (
                                <span className="text-muted-foreground tabular-nums">
                                    {i + 1}
                                </span>
                            )}
                            {name}
                        </button>
                    ))}
                </div>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                {step === 0 && (
                    <>
                        <Input
                            type="file"
                            accept="image/*"
                            multiple
                            aria-label={`${side} photos`}
                            onChange={(e) => {
                                const picked = Array.from(e.target.files ?? []);
                                onFiles(picked);
                                // A crop and guides drawn on the old first
                                // frame mean nothing on a new one.
                                onCrop(null);
                                onGuides(null);
                                setCropped(null);
                                setDetected(null);
                                setStep(1);

                                if (picked.length > 0) {
                                    void findCard(picked[0]);
                                }
                            }}
                        />

                        {files.length === 1 && (
                            <p className="rounded border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-xs text-amber-700 dark:text-amber-500">
                                One photo. Centering and the rectified card
                                still work, but{' '}
                                <strong>
                                    surface cannot be read from a single image
                                </strong>{' '}
                                — the method compares frames as the glare moves,
                                and one frame has nothing to compare against.
                            </p>
                        )}

                        {files.length > 1 && (
                            <p className="text-xs text-muted-foreground">
                                <Images className="mr-1 inline size-3.5" />
                                The crop and the guides are set on the first
                                frame and applied to all of them — cropping them
                                differently would misalign the frames the
                                surface read compares.
                            </p>
                        )}
                    </>
                )}

                {step === 1 && original && (
                    <>
                        <p className="text-xs text-muted-foreground">
                            {finding ? (
                                <>
                                    <Spinner className="mr-1 inline size-3.5" />
                                    Finding the card…
                                </>
                            ) : crop ? (
                                <>
                                    Cropped to the card automatically — nudge
                                    the edges if it clipped anything. Everything
                                    after this works on what you leave.
                                </>
                            ) : (
                                <>
                                    Trim the background away so the card fills
                                    the frame. Everything after this works on
                                    what you leave, which is what makes the
                                    guides worth anything.
                                </>
                            )}
                        </p>
                        <CropBox
                            src={original}
                            crop={crop}
                            onChange={onCrop}
                            alt={`${side} first frame`}
                        />
                        <div className="flex flex-wrap gap-2">
                            {crop && (
                                <Button size="sm" onClick={() => setStep(2)}>
                                    Next: guides
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={finding || files.length === 0}
                                onClick={() => void findCard(files[0])}
                            >
                                {finding ? (
                                    <Spinner className="size-3.5" />
                                ) : (
                                    <ScanSearch className="size-3.5" />
                                )}
                                Find the card
                            </Button>
                            {crop && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onCrop(null)}
                                >
                                    <RotateCcw className="size-3.5" />
                                    Clear crop
                                </Button>
                            )}
                        </div>
                    </>
                )}

                {step === 2 && (
                    <>
                        {!crop && (
                            <p className="rounded border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-xs text-amber-700 dark:text-amber-500">
                                No crop yet. Guides can be placed on the whole
                                photo, but on a card surrounded by background
                                both you and the model are aiming at an edge
                                that is nowhere near the frame.{' '}
                                <button
                                    type="button"
                                    className="underline underline-offset-2"
                                    onClick={() => setStep(1)}
                                >
                                    Crop first
                                </button>
                                .
                            </p>
                        )}

                        {working && !guides && (
                            <div className="flex flex-col gap-3">
                                <img
                                    src={working}
                                    alt={`${side} cropped`}
                                    className="w-full rounded border border-border"
                                />
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        disabled={asking}
                                        onClick={propose}
                                    >
                                        {asking ? (
                                            <Spinner className="size-3.5" />
                                        ) : (
                                            <Sparkles className="size-3.5" />
                                        )}
                                        Place guides with AI
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            onGuides(startingGuides())
                                        }
                                    >
                                        <Ruler className="size-3.5" />
                                        {detected
                                            ? 'Start from the detected edge'
                                            : 'Place them myself'}
                                    </Button>
                                </div>
                                {proposeError && (
                                    <span className="text-xs text-destructive">
                                        {proposeError}
                                    </span>
                                )}
                            </div>
                        )}

                        {working && guides && (
                            <div className="flex flex-col gap-2">
                                <p className="text-xs text-muted-foreground">
                                    Put the{' '}
                                    <span className="text-primary">blue</span>{' '}
                                    guide on the OUTER edge of the border — the
                                    card&rsquo;s own edge — and the{' '}
                                    <span className="text-amber-600">
                                        amber
                                    </span>{' '}
                                    one on its INNER edge, where the artwork
                                    starts. Drag corners, sides, or the middle.
                                    Check them even if the model placed them: a
                                    percent out is twenty points of centering.
                                </p>
                                <GuideOverlay
                                    src={working}
                                    guides={guides}
                                    onChange={onGuides}
                                    alt={`${side} guides`}
                                />
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={asking}
                                        onClick={propose}
                                    >
                                        {asking ? (
                                            <Spinner className="size-3.5" />
                                        ) : (
                                            <Sparkles className="size-3.5" />
                                        )}
                                        Re-place with AI
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => onGuides(null)}
                                    >
                                        <RotateCcw className="size-3.5" />
                                        Remove guides
                                    </Button>
                                    {proposeError && (
                                        <span className="text-xs text-destructive">
                                            {proposeError}
                                        </span>
                                    )}
                                </div>
                            </div>
                        )}

                        <div className="border-t border-border pt-3">
                            <p className="mb-2 text-xs text-muted-foreground">
                                Or type centering, as a grading report writes it
                                — TAG prints this side as e.g.{' '}
                                <code>46L/54R 47T/53B</code>. Guides win over
                                this when both are given: one measures these
                                photos, the other is a figure off somebody
                                else&rsquo;s report.
                            </p>
                            <div className="flex flex-wrap gap-3">
                                {EDGES.map((edge) => (
                                    <div
                                        key={edge}
                                        className="flex flex-col gap-1.5"
                                    >
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
                                                edge === 'left'
                                                    ? '46'
                                                    : undefined
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
                    </>
                )}
            </CardContent>
        </Card>
    );
}
