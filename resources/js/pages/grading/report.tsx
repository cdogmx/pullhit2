import { Head } from '@inertiajs/react';
import { Info, Sparkles } from 'lucide-react';
import {
    AttributeTiles,
    CenteringBars,
    MeasuredCard,
    StandardVerdicts,
} from '@/components/grading/breakdown';
import type { StandardVerdict } from '@/components/grading/breakdown';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type SideSummary = {
    usable: boolean | null;
    surface_assessable: boolean | null;
    surface: { score: number; bucket: string; defect_count: number } | null;
    frames_used: number | null;
    specular_range: number | null;
    canvas: { width: number; height: number } | null;
    images: { card?: string; detail?: string };
    guide: { x: number; y: number }[] | null;
    centering: {
        score: number;
        left: number;
        right: number;
        top: number;
        bottom: number;
    } | null;
};

type Props = {
    label: string | null;
    created_at: string | null;
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
    guides_source: string | null;
    centering_standards: StandardVerdict[];
    sides: Record<string, SideSummary>;
    actual_company: string | null;
    actual_grade: number | null;
    actual_cert: string | null;
    probability_of_actual: number | null;
    meta: { title: string; description: string };
};

const pct = (n: number) => `${Math.round(n * 100)}%`;

/**
 * The card as it was read, with the guide drawn back on it.
 *
 * The one thing a centering figure cannot tell you is whether the border it
 * measured to was the border. Putting the guide back over the picture it was
 * placed on answers that in a glance, and it is the first thing worth checking
 * whenever a number looks wrong.
 */
function ReadCard({ side, name }: { side: SideSummary; name: string }) {
    const card = side.images?.card;
    const detail = side.images?.detail;

    if (!card && !detail) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm capitalize">
                    {name} — as it was read
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
                {card && (
                    <figure className="flex flex-col gap-2">
                        <MeasuredCard
                            src={card}
                            guide={side.guide}
                            centering={side.centering}
                            alt={`${name}, straightened and measured`}
                        />
                        <figcaption className="text-xs text-muted-foreground">
                            Straightened, with the border it was measured to.
                            Each number is that margin&rsquo;s share of its axis
                            — a perfectly centred card reads 50 on all four. The{' '}
                            <span className="text-amber-600">amber</span> bands
                            are the left and right margins, the{' '}
                            <span className="text-sky-600">blue</span> top and
                            bottom.
                        </figcaption>
                    </figure>
                )}

                {detail && (
                    <figure className="flex flex-col gap-2">
                        <img
                            src={detail}
                            alt={`${name}, surface detail`}
                            className="w-full rounded border border-border bg-black/20"
                        />
                        <figcaption className="text-xs text-muted-foreground">
                            The surface map. Near-black except for scratches is
                            a clean read; artwork showing through means the
                            frames never aligned, and the defect count below is
                            measuring that instead of the card.
                        </figcaption>
                    </figure>
                )}
            </CardContent>
        </Card>
    );
}

export default function GradeReport({
    label,
    created_at,
    estimate,
    observed,
    guides_source,
    centering_standards,
    sides,
    actual_company,
    actual_grade,
    actual_cert,
    probability_of_actual,
    meta,
}: Props) {
    const caveats = Object.values(estimate.caveats ?? {});
    const ranked = Object.entries(estimate.probs ?? {}).sort(
        (a, b) => b[1] - a[1],
    );

    return (
        <>
            <Head title={meta.title}>
                <meta name="description" content={meta.description} />
                <meta property="og:title" content={meta.title} />
                <meta property="og:description" content={meta.description} />
            </Head>

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <div>
                    <p className="text-xs text-muted-foreground">
                        CardFoo · grade prediction
                    </p>
                    <h1 className="text-2xl font-bold tracking-tight">
                        {label ?? 'Untitled card'}
                    </h1>
                    <p className="text-xs text-muted-foreground">
                        Read from photos on {created_at}
                    </p>
                </div>

                {/* First, and unmissable. This is the output of a pipeline that
                    has not been checked against real outcomes yet, and it
                    usually has not seen three of the four things a grade counts.
                    A number shared without that is the one way this could
                    mislead somebody who was not in the room when it ran. */}
                <div className="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/5 px-4 py-3 text-sm">
                    <Info className="mt-0.5 size-4 shrink-0 text-amber-600" />
                    <div>
                        <p className="font-medium">
                            An estimate from photographs — not a grade.
                        </p>
                        <p className="text-muted-foreground">
                            It answers with a spread of likely grades rather
                            than one, and it only judges what the photos could
                            show. No grading company has seen this card on the
                            strength of this page.
                        </p>
                    </div>
                </div>

                {actual_grade !== null && (
                    <Card>
                        <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
                            <div>
                                <p className="text-xs text-muted-foreground">
                                    Actually graded
                                </p>
                                <p className="text-3xl font-bold tabular-nums">
                                    {actual_company ?? ''} {actual_grade}
                                </p>
                                {actual_cert && (
                                    <p className="text-xs text-muted-foreground">
                                        cert {actual_cert}
                                    </p>
                                )}
                            </div>
                            {probability_of_actual !== null && (
                                <div className="text-right">
                                    <p className="text-xs text-muted-foreground">
                                        We put this much on that grade
                                    </p>
                                    <p
                                        className={cn(
                                            'text-3xl font-bold tabular-nums',
                                            probability_of_actual >= 0.5
                                                ? 'text-[#047857] dark:text-emerald-400'
                                                : probability_of_actual >= 0.25
                                                  ? 'text-[#b45309] dark:text-amber-400'
                                                  : 'text-[#b91c1c] dark:text-red-400',
                                        )}
                                    >
                                        {pct(probability_of_actual)}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">
                            What we thought it would grade
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        {ranked.map(([grade, p]) => (
                            <div
                                key={grade}
                                className="flex items-center gap-3"
                            >
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
                            Condition score {estimate.score} ±{' '}
                            {Math.round(estimate.sigma)}
                            {estimate.limiting_attribute &&
                                ` · held back by ${estimate.limiting_attribute}`}
                            {!estimate.confident && ' · low confidence'}
                            {guides_source === 'ai' && (
                                <>
                                    {' '}
                                    ·{' '}
                                    <Badge
                                        variant="outline"
                                        className="gap-1 text-amber-600"
                                    >
                                        <Sparkles className="size-3" />
                                        guides placed by AI, unchecked
                                    </Badge>
                                </>
                            )}
                        </p>
                    </CardContent>
                </Card>

                <div className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium">
                        What was measured
                        <span className="ml-2 font-normal text-muted-foreground">
                            {observed.length} of 4 attributes
                        </span>
                    </h2>
                    <AttributeTiles
                        scores={estimate.attributes}
                        limitedBy={{}}
                    />
                </div>

                {centering_standards?.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                On centering, by each grader&rsquo;s own
                                standard
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <StandardVerdicts verdicts={centering_standards} />
                        </CardContent>
                    </Card>
                )}

                {Object.entries(sides).map(([name, side]) => (
                    <ReadCard key={`img-${name}`} side={side} name={name} />
                ))}

                {Object.entries(sides).map(
                    ([name, side]) =>
                        side.centering && (
                            <Card key={name}>
                                <CardHeader>
                                    <CardTitle className="text-sm capitalize">
                                        {name} centering
                                        <span className="ml-2 font-normal text-muted-foreground">
                                            {side.centering.score}
                                        </span>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <CenteringBars centering={side.centering} />
                                </CardContent>
                            </Card>
                        ),
                )}

                {/* The capture, in the open. A low score and a bad reading
                    look identical from the outside, and these three numbers are
                    what tells them apart — so they travel with the link rather
                    than living only on the machine that ran it. */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">
                            How it was read
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="py-2 pr-4">side</th>
                                    <th className="py-2 pr-4">frames</th>
                                    <th className="py-2 pr-4">glare moved</th>
                                    <th className="py-2 pr-4">surface</th>
                                    <th className="py-2">centering</th>
                                </tr>
                            </thead>
                            <tbody className="tabular-nums">
                                {Object.entries(sides).map(([name, side]) => (
                                    <tr
                                        key={name}
                                        className="border-b border-border/50"
                                    >
                                        <td className="py-2 pr-4 capitalize">
                                            {name}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {side.frames_used ?? '—'}
                                            {side.canvas && (
                                                <span className="ml-1 text-xs text-muted-foreground">
                                                    {side.canvas.width}×
                                                    {side.canvas.height}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {side.specular_range ?? '—'}
                                            {side.surface_assessable ===
                                                false && (
                                                <span className="ml-1 text-xs text-muted-foreground">
                                                    one photo
                                                </span>
                                            )}
                                            {side.surface_assessable &&
                                                side.usable === false && (
                                                    <span className="ml-1 text-xs text-amber-600">
                                                        too little
                                                    </span>
                                                )}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {side.surface
                                                ? `${side.surface.score} · ${side.surface.defect_count} defect${side.surface.defect_count === 1 ? '' : 's'}`
                                                : 'not assessed'}
                                        </td>
                                        <td className="py-2">
                                            {side.centering
                                                ? `${side.centering.score}`
                                                : 'not measured'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <p className="mt-3 text-xs text-muted-foreground">
                            &ldquo;Glare moved&rdquo; is how far the highlight
                            travelled between frames. Under 8 there was nothing
                            to compare and the surface could not be read — which
                            is a fact about the photos, not about the card.
                        </p>
                    </CardContent>
                </Card>

                {caveats.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                What we could not see
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="flex flex-col gap-2 text-xs text-muted-foreground">
                                {caveats.map((c) => (
                                    <li key={c}>· {c}</li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
