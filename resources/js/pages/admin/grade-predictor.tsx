import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Info, Save, Upload, X } from 'lucide-react';
import { useState } from 'react';
import {
    AttributeTiles,
    CenteringBars,
    DefectMap,
} from '@/components/grading/breakdown';
import type { Guides, Quad } from '@/components/grading/guide-overlay';
import type { SavedRun } from '@/components/grading/saved-runs';
import { SavedRuns } from '@/components/grading/saved-runs';
import { EMPTY_SPLIT, SideCapture } from '@/components/grading/side-capture';
import type { Split } from '@/components/grading/side-capture';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { csrf } from '@/lib/csrf';
import { cn } from '@/lib/utils';

type Defect = {
    x: number;
    y: number;
    length: number;
    elongation: number;
    strength: number;
};

type SideResult = {
    surface_assessable: boolean;
    usable: boolean;
    frames_used: number;
    specular_range: number | null;
    canvas: { width: number; height: number };
    surface: {
        defects: Defect[];
        defect_count: number;
        score: number;
        bucket: string;
    } | null;
    centering: {
        score: number;
        left: number;
        right: number;
        top: number;
        bottom: number;
    } | null;
    images: {
        albedo: string | null;
        detail: string | null;
        frames: string[];
    };
};

type Prediction = {
    sides: Record<string, SideResult>;
    estimate: {
        score: number;
        sigma: number;
        attributes: Record<string, number>;
        unseen: string[];
        probs: Record<string, number>;
        limiting_attribute: string | null;
        confident: boolean;
        caveats: Record<string, string>;
    };
    observed: string[];
    limited_by_side: Record<string, string>;
    took_ms: number;
};

type Props = {
    defaults: { max_input: number; canvas_width: number };
    sides: string[];
    saved: SavedRun[];
};

const EDGES = ['left', 'right', 'top', 'bottom'] as const;
const pct = (n: number) => `${Math.round(n * 100)}%`;

export default function GradePredictor({ defaults, sides, saved }: Props) {
    const [files, setFiles] = useState<Record<string, File[]>>({});
    const [crops, setCrops] = useState<Record<string, Quad | null>>({});
    const [guides, setGuides] = useState<Record<string, Guides | null>>({});
    const [splits, setSplits] = useState<Record<string, Split>>(
        Object.fromEntries(sides.map((s) => [s, { ...EMPTY_SPLIT }])),
    );
    const [maxInput, setMaxInput] = useState(String(defaults.max_input));
    const [canvasWidth, setCanvasWidth] = useState(
        String(defaults.canvas_width),
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<Prediction | null>(null);
    const [label, setLabel] = useState('');
    const [saving, setSaving] = useState(false);

    // The straightened card per side, as a data URI, reported up by the step
    // that made it — the picture the guides were placed on.
    const [straightened, setStraightened] = useState<Record<string, string>>(
        {},
    );

    // Whether the guides were proposed, and whether anybody moved them after.
    // It decides if a saved run can calibrate anything.
    const [proposed, setProposed] = useState<Record<string, boolean>>({});
    const [touched, setTouched] = useState<Record<string, boolean>>({});

    function guidesSource(): 'ai' | 'ai-adjusted' | 'manual' {
        const anyProposed = Object.values(proposed).some(Boolean);

        if (!anyProposed) {
            return 'manual';
        }

        return Object.values(touched).some(Boolean) ? 'ai-adjusted' : 'ai';
    }

    async function save() {
        if (!result) {
            return;
        }

        setSaving(true);

        try {
            await fetch('/admin/grade-predictor/predictions', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    label: label || null,
                    // The straightened card as it was actually worked on, plus
                    // the guide that was placed on it. Without the pair, a
                    // centering figure cannot be checked by anyone later.
                    sides: Object.fromEntries(
                        Object.entries(result.sides).map(([name, side]) => [
                            name,
                            {
                                ...side,
                                images: {
                                    card: straightened[name] ?? null,
                                    detail: side.images?.detail ?? null,
                                },
                                guide: guides[name]?.frame ?? null,
                            },
                        ]),
                    ),
                    estimate: result.estimate,
                    observed: result.observed,
                    guides_source: guidesSource(),
                }),
            });

            setLabel('');
            router.reload({ only: ['saved'] });
        } finally {
            setSaving(false);
        }
    }

    const given = sides.filter((s) => (files[s]?.length ?? 0) > 0);

    async function run() {
        if (given.length === 0) {
            setError(
                'Give at least one side — a front or a back set of photos.',
            );

            return;
        }

        setBusy(true);
        setError(null);
        setResult(null);

        const body = new FormData();
        body.append('max_input', maxInput);
        body.append('canvas_width', canvasWidth);

        try {
            for (const side of given) {
                const crop = crops[side];

                // The ORIGINAL frames go up. The card's corners go with them as
                // the outline guide, and the server rectifies every frame from
                // that one quad — the same warp the surface read already needs.
                // Sending pre-straightened images instead would mean warping
                // each frame in the browser, and a second implementation of a
                // transform this codebase already has fitted and tested.
                for (const file of files[side]) {
                    body.append(`${side}[]`, file);
                }

                for (const edge of EDGES) {
                    const v = splits[side]?.[edge] ?? '';

                    if (v.trim() !== '') {
                        body.append(`centering[${side}][${edge}]`, v.trim());
                    }
                }

                if (crop) {
                    crop.forEach((p, i) => {
                        body.append(
                            `guides[${side}][outline][${i}][x]`,
                            String(p.x),
                        );
                        body.append(
                            `guides[${side}][outline][${i}][y]`,
                            String(p.y),
                        );
                    });
                }

                const g = guides[side];

                if (g) {
                    // The inner border, as placed on the straightened card —
                    // which is card space already, so it goes as frame_uv and
                    // the server maps it no further.
                    g.frame.forEach((p, i) => {
                        body.append(
                            `guides[${side}][frame_uv][${i}][x]`,
                            String(p.x),
                        );
                        body.append(
                            `guides[${side}][frame_uv][${i}][y]`,
                            String(p.y),
                        );
                    });
                }
            }

            const response = await fetch('/admin/grade-predictor', {
                method: 'POST',
                body,
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') ?? '',
                },
            });

            const payload = await response.json();

            if (!response.ok) {
                setError(payload.message ?? 'That did not work.');

                return;
            }

            setResult(payload);
        } catch {
            setError('The request failed before it reached the pipeline.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <Head title="Grade predictor" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <p className="text-xs text-muted-foreground">Admin</p>
                    <h1 className="text-2xl font-bold tracking-tight">
                        Grade predictor bench
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                        For a surface read, shoot 3–5 photos per side, tilting
                        the card between shots so the glare sweeps across it.
                        That moving reflection is the entire signal. One photo
                        is accepted and still gives you the rectified card and
                        centering — it just cannot say anything about surface.
                        Photos of different sizes are matched automatically.
                    </p>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    {sides.map((side) => (
                        <SideCapture
                            key={side}
                            side={side}
                            files={files[side] ?? []}
                            crop={crops[side] ?? null}
                            split={splits[side] ?? EMPTY_SPLIT}
                            guides={guides[side] ?? null}
                            onFiles={(f) =>
                                setFiles((prev) => ({ ...prev, [side]: f }))
                            }
                            onCrop={(c) =>
                                setCrops((prev) => ({ ...prev, [side]: c }))
                            }
                            onGuides={(g) => {
                                setGuides((prev) => ({ ...prev, [side]: g }));
                                setTouched((prev) => ({
                                    ...prev,
                                    [side]: true,
                                }));
                            }}
                            onStraightened={(dataUri) =>
                                setStraightened((prev) => ({
                                    ...prev,
                                    [side]: dataUri,
                                }))
                            }
                            onProposed={(g) => {
                                setGuides((prev) => ({ ...prev, [side]: g }));
                                setProposed((prev) => ({
                                    ...prev,
                                    [side]: true,
                                }));
                                // A fresh proposal nobody has moved yet.
                                setTouched((prev) => ({
                                    ...prev,
                                    [side]: false,
                                }));
                            }}
                            onSplit={(edge, value) =>
                                setSplits((prev) => ({
                                    ...prev,
                                    [side]: {
                                        ...(prev[side] ?? EMPTY_SPLIT),
                                        [edge]: value,
                                    },
                                }))
                            }
                        />
                    ))}
                </div>

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-4 pt-6">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="max-input">Downscale to (px)</Label>
                            <Input
                                id="max-input"
                                value={maxInput}
                                onChange={(e) => setMaxInput(e.target.value)}
                                className="w-32"
                            />
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="canvas">Canvas width (px)</Label>
                            <Input
                                id="canvas"
                                value={canvasWidth}
                                onChange={(e) => setCanvasWidth(e.target.value)}
                                className="w-32"
                            />
                        </div>

                        <Button onClick={run} disabled={busy}>
                            {busy ? (
                                <Spinner className="size-4" />
                            ) : (
                                <Upload className="size-4" />
                            )}
                            Run pipeline
                            {given.length > 0 && ` (${given.join(' + ')})`}
                        </Button>
                    </CardContent>
                </Card>

                {error && (
                    <div className="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm">
                        <X className="mt-0.5 size-4 text-destructive" />
                        <span>{error}</span>
                    </div>
                )}

                {result && (
                    <Card>
                        <CardContent className="flex flex-wrap items-end gap-3 pt-6">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="run-label">
                                    Keep this run as
                                </Label>
                                <Input
                                    id="run-label"
                                    value={label}
                                    onChange={(e) => setLabel(e.target.value)}
                                    placeholder="Milotic ex 237/191"
                                    className="w-72"
                                />
                            </div>
                            <Button
                                variant="outline"
                                disabled={saving}
                                onClick={save}
                            >
                                {saving ? (
                                    <Spinner className="size-4" />
                                ) : (
                                    <Save className="size-4" />
                                )}
                                Save prediction
                            </Button>
                            <p className="max-w-md text-xs text-muted-foreground">
                                Saved as <strong>{guidesSource()}</strong>{' '}
                                guides. Send the card off, then record what came
                                back — a run beside its real grade is the only
                                thing that says whether any of this works.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {result && <Results result={result} />}

                <SavedRuns runs={saved} />
            </div>
        </>
    );
}

function Results({ result }: { result: Prediction }) {
    const dist = result.estimate.probs ?? {};
    const caveats = Object.values(result.estimate.caveats ?? {});
    const limiting = result.estimate.limiting_attribute;

    return (
        <div className="flex flex-col gap-6">
            <Card>
                <CardHeader>
                    <CardTitle className="text-sm">
                        Grade distribution
                    </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <p className="text-xs text-muted-foreground">
                        A distribution, never a grade. Across both sides the
                        worst reading of each attribute counts — a scratch on
                        the back holds a card back exactly as one on the front.
                    </p>

                    <AttributeTiles
                        scores={result.estimate.attributes}
                        limitedBy={result.limited_by_side}
                    />

                    {Object.entries(dist).map(([grade, p]) => (
                        <div key={grade} className="flex items-center gap-3">
                            <span className="w-14 text-sm font-medium tabular-nums">
                                {grade}
                            </span>
                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        grade === 'other'
                                            ? 'bg-muted-foreground/40'
                                            : 'bg-primary',
                                    )}
                                    style={{ width: pct(p) }}
                                />
                            </div>
                            <span className="w-12 text-right text-sm text-muted-foreground tabular-nums">
                                {pct(p)}
                            </span>
                        </div>
                    ))}

                    <p className="text-xs text-muted-foreground">
                        Condition score {result.estimate.score} ±{' '}
                        {Math.round(result.estimate.sigma)}
                        {limiting && (
                            <>
                                {' '}
                                · limited by {limiting}
                                {result.limited_by_side?.[limiting] &&
                                    ` on the ${result.limited_by_side[limiting]}`}
                            </>
                        )}
                        {!result.estimate.confident && ' · low confidence'} ·{' '}
                        {result.took_ms} ms
                    </p>

                    {caveats.length > 0 && (
                        <ul className="flex flex-col gap-1.5 border-t border-border pt-3 text-xs text-muted-foreground">
                            {caveats.map((c) => (
                                <li key={c}>· {c}</li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            {Object.entries(result.sides).map(([name, side]) => (
                <SideResults key={name} name={name} side={side} />
            ))}
        </div>
    );
}

function SideResults({ name, side }: { name: string; side: SideResult }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm capitalize">
                    {name}
                    <span className="ml-2 font-normal text-muted-foreground">
                        {side.usable && side.surface
                            ? `${side.surface.bucket} · score ${side.surface.score} · ${side.surface.defect_count} defect${side.surface.defect_count === 1 ? '' : 's'}`
                            : side.surface_assessable
                              ? 'unusable capture'
                              : 'surface not assessed'}
                        {side.centering &&
                            ` · centering ${side.centering.score} (${side.centering.left}/${side.centering.right}, ${side.centering.top}/${side.centering.bottom})`}
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {/* Two different failures, kept apart. One photo CANNOT show a
                    surface — physics, not a bad shot. Several photos that never
                    moved the glare COULD have — that is a re-shoot. */}
                {!side.surface_assessable && (
                    <div className="flex items-start gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                        <Info className="mt-0.5 size-4 text-muted-foreground" />
                        <div>
                            <p className="font-medium">Surface not assessed</p>
                            <p className="text-muted-foreground">
                                One photo of this side. The surface read
                                compares frames as the glare moves across the
                                card, so a single image carries no surface
                                information at all — this is not a claim the
                                card is clean. Add a second, tilted shot.
                            </p>
                        </div>
                    </div>
                )}

                {side.surface_assessable && !side.usable && (
                    <div className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 text-amber-600" />
                        <div>
                            <p className="font-medium">
                                Not a clean card — an unreadable capture
                            </p>
                            <p className="text-muted-foreground">
                                The highlight barely moved between frames
                                (specular range {side.specular_range}), so there
                                was nothing to difference. Re-shoot this side,
                                tilting more so the glare visibly sweeps across
                                it.
                            </p>
                        </div>
                    </div>
                )}

                {side.centering && <CenteringBars centering={side.centering} />}

                {side.images.albedo && side.images.detail && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Figure
                            src={side.images.albedo}
                            title="Albedo"
                            caption="Should look like a clean, flat, glare-free card."
                        />
                        <Figure
                            src={side.images.detail}
                            title="Detail"
                            caption="Should be near-black except for scratches. Artwork here means the frames did not align."
                        />
                    </div>
                )}

                <div>
                    <p className="mb-2 text-xs text-muted-foreground">
                        Rectified · {side.frames_used} frame
                        {side.frames_used === 1 ? '' : 's'} ·{' '}
                        {side.canvas.width}×{side.canvas.height}
                        {side.specular_range !== null &&
                            ` · specular range ${side.specular_range}`}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {side.images.frames.map((src, i) => (
                            <img
                                key={i}
                                src={src}
                                alt={`${name} rectified frame ${i + 1}`}
                                className="h-40 rounded border border-border"
                            />
                        ))}
                    </div>
                </div>

                {(side.surface?.defect_count ?? 0) > 0 &&
                    side.images.detail && (
                        <DefectMap
                            src={side.images.detail}
                            defects={side.surface!.defects}
                            width={side.canvas.width}
                            height={side.canvas.height}
                        />
                    )}
            </CardContent>
        </Card>
    );
}

function Figure({
    src,
    title,
    caption,
}: {
    src: string;
    title: string;
    caption: string;
}) {
    return (
        <figure className="flex flex-col gap-2">
            <img
                src={src}
                alt={title}
                className="w-full rounded border border-border bg-black/20"
            />
            <figcaption className="text-xs text-muted-foreground">
                <span className="font-medium text-foreground">{title}</span> —{' '}
                {caption}
            </figcaption>
        </figure>
    );
}
