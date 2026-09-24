import { Head } from '@inertiajs/react';
import { AlertTriangle, Upload, X } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

type Defect = {
    x: number;
    y: number;
    length: number;
    elongation: number;
    strength: number;
};

type Prediction = {
    usable: boolean;
    frames_used: number;
    specular_range: number;
    canvas: { width: number; height: number };
    surface: {
        defects: Defect[];
        defect_count: number;
        score: number;
        bucket: string;
        usable: boolean;
    };
    centering: { score: number; describe?: string } | null;
    estimate: {
        score: number;
        sigma: number;
        attributes: Record<string, number>;
        unseen: string[];
        probs: Record<string, number>;
        limiting_attribute: string | null;
        confident: boolean;
        caveats: string[];
    };
    observed: string[];
    images: { albedo: string; detail: string; frames: string[] };
    took_ms: number;
};

type Props = {
    defaults: { max_input: number; canvas_width: number };
};

const pct = (n: number) => `${Math.round(n * 100)}%`;

export default function GradePredictor({ defaults }: Props) {
    const [files, setFiles] = useState<File[]>([]);
    const [maxInput, setMaxInput] = useState(String(defaults.max_input));
    const [canvasWidth, setCanvasWidth] = useState(
        String(defaults.canvas_width),
    );
    const [inner, setInner] = useState({
        left: '',
        right: '',
        top: '',
        bottom: '',
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<Prediction | null>(null);

    async function run() {
        if (files.length < 2) {
            setError(
                'Two or more photos — a single image carries no surface information.',
            );

            return;
        }

        setBusy(true);
        setError(null);
        setResult(null);

        const body = new FormData();
        files.forEach((f) => body.append('photos[]', f));
        body.append('max_input', maxInput);
        body.append('canvas_width', canvasWidth);

        for (const [k, v] of Object.entries(inner)) {
            if (v.trim() !== '') {
                // Entered as percentages because that is how a grading report
                // writes them; the pipeline wants a fraction.
                body.append(`inner[${k}]`, String(Number(v) / 100));
            }
        }

        try {
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
                        Shoot 3–5 photos of one card, tilting it between shots
                        so the glare sweeps across the surface. That moving
                        reflection is the entire signal — evenly lit, glare-free
                        photos carry no surface information at all. Every photo
                        must be the same pixel size.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">Capture</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="photos">Photos (2–8)</Label>
                                <Input
                                    id="photos"
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    className="w-80"
                                    onChange={(e) =>
                                        setFiles(
                                            Array.from(e.target.files ?? []),
                                        )
                                    }
                                />
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="max-input">
                                    Downscale to (px)
                                </Label>
                                <Input
                                    id="max-input"
                                    value={maxInput}
                                    onChange={(e) =>
                                        setMaxInput(e.target.value)
                                    }
                                    className="w-32"
                                />
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="canvas">
                                    Canvas width (px)
                                </Label>
                                <Input
                                    id="canvas"
                                    value={canvasWidth}
                                    onChange={(e) =>
                                        setCanvasWidth(e.target.value)
                                    }
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
                            </Button>
                        </div>

                        {files.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                {files.map((f) => (
                                    <Badge key={f.name} variant="secondary">
                                        {f.name}
                                    </Badge>
                                ))}
                            </div>
                        )}

                        <div>
                            <p className="mb-2 text-xs text-muted-foreground">
                                Optional — the artwork border as a percentage of
                                the card, for centering. Nothing detects this
                                yet, so it is measured only if you type it.
                            </p>
                            <div className="flex flex-wrap gap-3">
                                {(
                                    ['left', 'right', 'top', 'bottom'] as const
                                ).map((side) => (
                                    <div
                                        key={side}
                                        className="flex flex-col gap-1.5"
                                    >
                                        <Label
                                            htmlFor={`inner-${side}`}
                                            className="capitalize"
                                        >
                                            {side}
                                        </Label>
                                        <Input
                                            id={`inner-${side}`}
                                            value={inner[side]}
                                            placeholder="%"
                                            className="w-24"
                                            onChange={(e) =>
                                                setInner((prev) => ({
                                                    ...prev,
                                                    [side]: e.target.value,
                                                }))
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {error && (
                    <div className="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm">
                        <X className="mt-0.5 size-4 text-destructive" />
                        <span>{error}</span>
                    </div>
                )}

                {result && <Results result={result} />}
            </div>
        </>
    );
}

function Results({ result }: { result: Prediction }) {
    const dist = result.estimate.probs ?? {};

    return (
        <div className="flex flex-col gap-6">
            {/* The capture check comes first, because an unusable sequence makes
                every number under it meaningless rather than merely uncertain. */}
            {!result.usable && (
                <div className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-sm">
                    <AlertTriangle className="mt-0.5 size-4 text-amber-600" />
                    <div>
                        <p className="font-medium">
                            Unusable capture — not a clean card
                        </p>
                        <p className="text-muted-foreground">
                            The highlight barely moved between frames (specular
                            range {result.specular_range}), so there was nothing
                            to difference. Re-shoot, tilting more so the glare
                            visibly sweeps across the card.
                        </p>
                    </div>
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Specular range" value={result.specular_range} />
                <Stat
                    label="Frames used"
                    value={`${result.frames_used} · ${result.canvas.width}×${result.canvas.height}`}
                />
                <Stat
                    label="Surface"
                    value={
                        result.usable
                            ? `${result.surface.bucket} (${result.surface.score})`
                            : '—'
                    }
                />
                <Stat label="Took" value={`${result.took_ms} ms`} />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="text-sm">
                        Grade distribution
                    </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <p className="text-xs text-muted-foreground">
                        A distribution, never a grade. Observed:{' '}
                        {result.observed.length > 0
                            ? result.observed.join(', ')
                            : 'nothing'}
                        {result.estimate.unseen.length > 0 && (
                            <>
                                {' '}
                                · unseen (penalised):{' '}
                                {result.estimate.unseen.join(', ')}
                            </>
                        )}
                    </p>

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
                        {result.estimate.limiting_attribute &&
                            ` · limited by ${result.estimate.limiting_attribute}`}
                        {result.centering &&
                            ` · centering ${result.centering.score}`}
                        {!result.estimate.confident && ' · low confidence'}
                    </p>

                    {result.estimate.caveats.length > 0 && (
                        <ul className="flex flex-col gap-1 text-xs text-muted-foreground">
                            {result.estimate.caveats.map((c) => (
                                <li key={c}>· {c}</li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            {/* The docblock is explicit that these must be judged by eye: a
                detail map showing artwork means the frames did not align, and
                that is indistinguishable from a scratched card in the numbers. */}
            <Card>
                <CardHeader>
                    <CardTitle className="text-sm">
                        Intermediates — judge these by eye
                    </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Figure
                            src={result.images.albedo}
                            title="Albedo"
                            caption="Should look like a clean, flat, glare-free card."
                        />
                        <Figure
                            src={result.images.detail}
                            title="Detail"
                            caption="Should be near-black except for scratches. Artwork here means the frames did not align."
                        />
                    </div>

                    <div>
                        <p className="mb-2 text-xs text-muted-foreground">
                            Rectified frames
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {result.images.frames.map((src, i) => (
                                <img
                                    key={i}
                                    src={src}
                                    alt={`Rectified frame ${i + 1}`}
                                    className="h-40 rounded border border-border"
                                />
                            ))}
                        </div>
                    </div>
                </CardContent>
            </Card>

            {result.surface.defect_count > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">
                            Defects ({result.surface.defect_count})
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="py-2 pr-4">x</th>
                                    <th className="py-2 pr-4">y</th>
                                    <th className="py-2 pr-4">length</th>
                                    <th className="py-2 pr-4">elongation</th>
                                    <th className="py-2">strength</th>
                                </tr>
                            </thead>
                            <tbody className="tabular-nums">
                                {result.surface.defects
                                    .slice(0, 40)
                                    .map((d, i) => (
                                        <tr
                                            key={i}
                                            className="border-b border-border/50"
                                        >
                                            <td className="py-1.5 pr-4">
                                                {Math.round(d.x)}
                                            </td>
                                            <td className="py-1.5 pr-4">
                                                {Math.round(d.y)}
                                            </td>
                                            <td className="py-1.5 pr-4">
                                                {Math.round(d.length)}
                                            </td>
                                            <td className="py-1.5 pr-4">
                                                {d.elongation.toFixed(1)}
                                            </td>
                                            <td className="py-1.5">
                                                {d.strength.toFixed(1)}
                                            </td>
                                        </tr>
                                    ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string | number }) {
    return (
        <Card>
            <CardContent className="pt-6">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="mt-1 text-xl font-bold tracking-tight tabular-nums">
                    {value}
                </p>
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
