import {
    Check,
    Images,
    RotateCcw,
    Ruler,
    ScanSearch,
    Sparkles,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    DEFAULT_GUIDES,
    GuideOverlay,
    insetQuad,
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

const STEPS = ['Photos', 'Frame', 'Guides'] as const;

type Props = {
    side: string;
    files: File[];
    /** The card's four corners in the original photo — the crop. */
    crop: Quad | null;
    split: Split;
    guides: Guides | null;
    onFiles: (files: File[]) => void;
    onCrop: (crop: Quad | null) => void;
    onSplit: (edge: string, value: string) => void;
    onGuides: (guides: Guides | null) => void;
    onProposed: (guides: Guides) => void;
    /** The straightened card, so the saved run can keep it. */
    onStraightened: (dataUri: string) => void;
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

/**
 * The centering the guide currently describes, as it is dragged.
 *
 * Placing a border blind and reading the answer afterwards is the whole
 * difficulty: on a 700px card a two-pixel slip is three points of split and
 * roughly thirty of score, which is more than the gap between a real TAG
 * report and our first measurement of the same card. Shown live, the eye can
 * close that — nudge until the numbers stop moving and the line sits on the
 * border.
 *
 * The same arithmetic the server does: the card is straightened, so its own
 * edge is the frame and the margins are simply how far the guide sits inside.
 */
function liveCentering(quad: Guides['frame'] | undefined) {
    if (!quad || quad.length !== 4) {
        return null;
    }

    const left = Math.min(...quad.map((p) => p.x));
    const right = 1 - Math.max(...quad.map((p) => p.x));
    const top = Math.min(...quad.map((p) => p.y));
    const bottom = 1 - Math.max(...quad.map((p) => p.y));

    if (left + right <= 0 || top + bottom <= 0) {
        return null;
    }

    const leftPct = (left / (left + right)) * 100;
    const topPct = (top / (top + bottom)) * 100;

    return {
        left: leftPct,
        right: 100 - leftPct,
        top: topPct,
        bottom: 100 - topPct,
        worst: Math.max(Math.abs(leftPct - 50), Math.abs(topPct - 50)),
        // The same line CenteringMeasurer uses: 1000 less 9.06 a point.
        score: Math.max(
            0,
            Math.round(
                1000 -
                    9.06 *
                        Math.max(Math.abs(leftPct - 50), Math.abs(topPct - 50)),
            ),
        ),
    };
}

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
    onStraightened,
}: Props) {
    const [step, setStep] = useState(0);
    const [asking, setAsking] = useState(false);
    const [finding, setFinding] = useState(false);
    const [proposeError, setProposeError] = useState<string | null>(null);

    // The card's outer edge as the detector found it, in the ORIGINAL photo's
    // coordinates. Kept so the outer guide can start on the real edge instead
    // of on an arbitrary inset rectangle.
    const [detected, setDetected] = useState<Quad | null>(null);
    const [straight, setStraight] = useState<{
        file: File;
        url: string;
        /** The corners this was warped from. */
        key: string;
    } | null>(null);
    const [straightening, setStraightening] = useState(false);

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

    // Which corners are being asked about right now. A straightened card is
    // only the answer to the corners it was made from: move one and the
    // picture on screen is of a crop that no longer exists.
    const cropKey = crop ? JSON.stringify(crop) : null;

    // The card, straightened out of the photo. This is what the guides are
    // placed on, what the model is shown, and what the server is sent.
    //
    // Straightened rather than merely cropped because a card is rarely
    // square-on in a hand-held shot, and an axis-aligned crop of a tilted card
    // keeps the tilt plus a wedge of background in every corner. Both make the
    // next step harder: a guide dragged along a crooked border is fighting the
    // picture, and the model reads a crooked card as a card with a crooked
    // border.
    useEffect(() => {
        if (!crop || files.length === 0) {
            return;
        }

        let stale = false;
        let url: string | null = null;

        // Dragging a corner changes `crop` on every pointer move. Without this
        // wait that is one request per mouse move — a flood the server answers
        // slower and slower, while the button that depends on the last of them
        // stays spinning. Straighten once the hand stops.
        const timer = setTimeout(() => {
            void (async () => {
                setStraightening(true);

                try {
                    const body = new FormData();
                    body.append('photo', files[0]);
                    body.append('width', '700');
                    crop.forEach((p, i) => {
                        body.append(`quad[${i}][x]`, String(p.x));
                        body.append(`quad[${i}][y]`, String(p.y));
                    });

                    const response = await fetch(
                        '/admin/grade-predictor/deskew',
                        {
                            method: 'POST',
                            body,
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrf(),
                            },
                        },
                    );

                    if (!response.ok || stale) {
                        return;
                    }

                    const payload = await response.json();
                    const blob = await (await fetch(payload.image)).blob();
                    const file = new File([blob], 'straight.png', {
                        type: 'image/png',
                    });

                    if (stale) {
                        return;
                    }

                    // Hand the picture up as a data URI too: the saved run
                    // keeps it, and a guide is only meaningful against the
                    // picture it was placed on.
                    onStraightened(payload.image as string);

                    url = URL.createObjectURL(file);

                    // Replace, and release the one being replaced. Revoking on
                    // effect cleanup instead killed the URL that state was
                    // still displaying, which is a broken image rather than a
                    // stale one.
                    setStraight((previous) => {
                        if (previous) {
                            URL.revokeObjectURL(previous.url);
                        }

                        return { file, url: url!, key: cropKey! };
                    });
                } finally {
                    // Cleared whatever happened. Leaving it set on a superseded
                    // or failed request is what pinned the spinner on.
                    setStraightening(false);
                }
            })();
        }, 350);

        return () => {
            stale = true;
            clearTimeout(timer);
        };
        // cropKey stands in for crop: the object is new on every pointer move,
        // its contents are not.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cropKey, files]);

    // Once framed, every later step works on the straightened card — but only
    // the one warped from the corners currently set. Showing the previous one
    // while a new warp is in flight puts the wrong card under the guides, and
    // a guide placed on the wrong card measures nothing.
    const fresh = straight && straight.key === cropKey ? straight : null;
    const working = crop ? (fresh?.url ?? null) : original;
    const workingFile = crop ? fresh?.file : files[0];

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
            // The detected quad IS the frame to straighten from.
            setDetected(payload.outline as Quad);
            onCrop(payload.outline as Quad);
        } catch {
            // Same — a failed detection just means cropping by hand.
        } finally {
            setFinding(false);
        }
    }

    /**
     * Starting guides on the straightened card.
     *
     * The card has already been warped to fill the frame, so its outer edge is
     * the frame — the blue guide starts there and usually needs no touching.
     * The inner border has no detector, because card designs vary far too much
     * for a design-agnostic one, so it starts as an inset and is the one thing
     * actually left to place.
     */
    function startingGuides(): Guides {
        return crop && straight
            ? { outline: insetQuad(0.004), frame: insetQuad(0.07) }
            : DEFAULT_GUIDES;
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
                                setStraight(null);
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
                                    Put a corner on each corner of the card. It
                                    is straightened from these, so a card shot
                                    crooked comes out square — drag any corner
                                    that is off.
                                </>
                            ) : (
                                <>
                                    Mark the card&rsquo;s four corners. It gets
                                    straightened and cropped from them, so the
                                    card need not be square-on in the photo.
                                </>
                            )}
                        </p>

                        <GuideOverlay
                            src={original}
                            guides={{
                                outline: crop ?? insetQuad(0.08),
                                frame: crop ?? insetQuad(0.08),
                            }}
                            only={['outline']}
                            hint="Zoom in to sit a corner exactly on the card's corner."
                            onChange={(g) => onCrop(g.outline)}
                            alt={`${side} first frame`}
                        />

                        {(fresh || straightening) && (
                            <div className="flex flex-col gap-2">
                                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    Straightened
                                    {straightening && (
                                        <Spinner className="size-3" />
                                    )}
                                </p>
                                {fresh && (
                                    <img
                                        src={fresh.url}
                                        alt={`${side} straightened`}
                                        className={cn(
                                            'w-40 rounded border border-border transition-opacity',
                                            straightening && 'opacity-50',
                                        )}
                                    />
                                )}
                            </div>
                        )}

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
                                    Start over
                                </Button>
                            )}
                        </div>
                    </>
                )}

                {step === 2 && (
                    <>
                        {!crop && (
                            <p className="rounded border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-xs text-amber-700 dark:text-amber-500">
                                The card has not been framed yet. Guides can go
                                on the raw photo, but on a card that is small,
                                crooked, or surrounded by background, both you
                                and the model are aiming at an edge that is
                                nowhere near the frame.{' '}
                                <button
                                    type="button"
                                    className="underline underline-offset-2"
                                    onClick={() => setStep(1)}
                                >
                                    Frame it first
                                </button>
                                .
                            </p>
                        )}

                        {crop && !fresh && (
                            <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                <Spinner className="size-3.5" />
                                Straightening the card…
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
                                    The card is straightened, so its outer edge
                                    is the frame — only the{' '}
                                    <span className="text-amber-600">
                                        inner
                                    </span>{' '}
                                    edge of the border is left to place, where
                                    the artwork starts. Drag corners, sides, or
                                    the middle. Check it even if the model
                                    placed it: a percent out is twenty points of
                                    centering.
                                </p>
                                <LiveReadout
                                    reading={liveCentering(guides.frame)}
                                />
                                <GuideOverlay
                                    src={working}
                                    guides={guides}
                                    only={['frame']}
                                    hint="Zoom in to sit a corner exactly on the inner border."
                                    onChange={onGuides}
                                    alt={`${side} inner border`}
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

/** The live split, in the shape a grading report prints it. */
function LiveReadout({
    reading,
}: {
    reading: ReturnType<typeof liveCentering>;
}) {
    if (!reading) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md border border-border bg-muted/40 px-3 py-2 text-xs tabular-nums">
            <span className="font-medium">
                {reading.left.toFixed(1)}L / {reading.right.toFixed(1)}R
            </span>
            <span className="font-medium">
                {reading.top.toFixed(1)}T / {reading.bottom.toFixed(1)}B
            </span>
            <span className="text-muted-foreground">
                {reading.worst.toFixed(1)} off centre · scores {reading.score}
            </span>
        </div>
    );
}
