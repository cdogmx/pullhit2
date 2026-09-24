import {
    CircleHelp,
    CircleCheck,
    TriangleAlert,
    OctagonAlert,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The four attributes a full grade accounts for — the same list, in the same
 * order, that ConditionRollup uses and that a TAG report prints.
 */
export const ATTRIBUTES = ['centering', 'corners', 'edges', 'surface'] as const;

export type Attribute = (typeof ATTRIBUTES)[number];

/**
 * Status, not identity.
 *
 * These four states are a reserved status palette and never a categorical one:
 * every tile carries an icon and a word as well as a colour, because "we did
 * not look" must never be distinguishable from "we looked and it was fine" by
 * hue alone. The palette validates at ΔE 29 normal vision and ΔE 21 protanopia
 * between the three coloured states; amber sits below 3:1 against the surface,
 * which is discharged by the number and label every tile shows.
 */
const STATES = {
    good: {
        label: 'Good',
        Icon: CircleCheck,
        ink: 'text-[#047857] dark:text-emerald-400',
        bar: 'bg-[#047857] dark:bg-emerald-400',
        edge: 'border-[#047857]/30',
    },
    fair: {
        label: 'Fair',
        Icon: TriangleAlert,
        ink: 'text-[#b45309] dark:text-amber-400',
        bar: 'bg-[#f59e0b]',
        edge: 'border-[#f59e0b]/40',
    },
    poor: {
        label: 'Poor',
        Icon: OctagonAlert,
        ink: 'text-[#b91c1c] dark:text-red-400',
        bar: 'bg-[#b91c1c] dark:bg-red-400',
        edge: 'border-[#b91c1c]/30',
    },
    unseen: {
        label: 'Not assessed',
        Icon: CircleHelp,
        ink: 'text-muted-foreground',
        bar: 'bg-muted-foreground/40',
        edge: 'border-border',
    },
} as const;

/** A 0–1000 attribute score, banded. Null is "not assessed", never "zero". */
function stateOf(score: number | null): keyof typeof STATES {
    if (score === null) {
        return 'unseen';
    }

    // Banded off the same line the centering score is fitted to: the Griffey's
    // 970 is a TAG 10's centering, and PSA's tolerances put a 9 near 900.
    return score >= 940 ? 'good' : score >= 860 ? 'fair' : 'poor';
}

export function AttributeTiles({
    scores,
    limitedBy,
}: {
    scores: Partial<Record<Attribute, number | null>>;
    limitedBy: Partial<Record<string, string>>;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {ATTRIBUTES.map((attribute) => {
                const score = scores[attribute] ?? null;
                const state = STATES[stateOf(score)];
                const side = limitedBy[attribute];

                return (
                    <div
                        key={attribute}
                        className={cn(
                            'flex flex-col gap-2 rounded-lg border bg-card p-3',
                            state.edge,
                        )}
                    >
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                {attribute}
                            </span>
                            <state.Icon
                                className={cn('size-4', state.ink)}
                                strokeWidth={1.75}
                                aria-hidden
                            />
                        </div>

                        <div className="flex items-baseline gap-2">
                            <span
                                className={cn(
                                    'text-2xl font-bold tabular-nums',
                                    score === null
                                        ? 'text-muted-foreground'
                                        : state.ink,
                                )}
                            >
                                {score ?? '—'}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {state.label}
                            </span>
                        </div>

                        {/* The bar is the score against the 1000 a perfect
                            attribute scores — magnitude, so one hue. */}
                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn('h-full rounded-full', state.bar)}
                                style={{
                                    width: `${((score ?? 0) / 1000) * 100}%`,
                                }}
                            />
                        </div>

                        <p className="text-xs text-muted-foreground">
                            {score === null
                                ? 'no detector yet'
                                : side
                                  ? `worse on the ${side}`
                                  : 'measured'}
                        </p>
                    </div>
                );
            })}
        </div>
    );
}

/**
 * Centering, drawn the way it is measured: a split about the middle.
 *
 * A bar with a centre tick, not two numbers. The distance between the join and
 * the tick IS the defect, and that is far easier to see than to read off
 * "46/54" — which is also exactly how a grading report prints it, so the two
 * can be compared at a glance.
 */
export function CenteringBars({
    centering,
}: {
    centering: {
        left: number;
        right: number;
        top: number;
        bottom: number;
        score: number;
    };
}) {
    const axes = [
        { name: 'left / right', a: centering.left, b: centering.right },
        { name: 'top / bottom', a: centering.top, b: centering.bottom },
    ];

    return (
        <div className="flex flex-col gap-3">
            {axes.map((axis) => {
                const off = Math.abs(axis.a - 50);

                return (
                    <div key={axis.name} className="flex flex-col gap-1">
                        <div className="flex items-baseline justify-between text-xs">
                            <span className="text-muted-foreground">
                                {axis.name}
                            </span>
                            <span className="tabular-nums">
                                {axis.a.toFixed(1)} / {axis.b.toFixed(1)}
                                <span className="ml-2 text-muted-foreground">
                                    {off.toFixed(1)} off centre
                                </span>
                            </span>
                        </div>

                        <div className="relative h-3 overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full bg-primary/70"
                                style={{ width: `${axis.a}%` }}
                            />
                            {/* Dead centre, which is what the split is judged
                                against. */}
                            <div
                                className="absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-foreground/60"
                                aria-hidden
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

/**
 * Where the defects are, on the card they are on.
 *
 * Plotted over the detail map rather than listed as coordinates, because the
 * question a person actually asks is "is that a scratch, or did the frames
 * fail to align" — and a cluster of marks following the artwork answers it
 * instantly where a table of x/y never will.
 */
export function DefectMap({
    src,
    defects,
    width,
    height,
}: {
    src: string;
    defects: { x: number; y: number; length: number; strength: number }[];
    width: number;
    height: number;
}) {
    const strongest = Math.max(1, ...defects.map((d) => d.strength));

    return (
        <figure className="flex flex-col gap-2">
            <div className="relative overflow-hidden rounded border border-border bg-black/20">
                <img src={src} alt="Defect map" className="w-full" />
                <svg
                    className="pointer-events-none absolute inset-0 size-full"
                    viewBox={`0 0 ${width} ${height}`}
                    preserveAspectRatio="none"
                >
                    {defects.map((d, i) => (
                        <circle
                            key={i}
                            cx={d.x}
                            cy={d.y}
                            r={Math.max(3, d.length / 2)}
                            fill="none"
                            stroke="#f59e0b"
                            strokeWidth={1.5}
                            vectorEffect="non-scaling-stroke"
                            // Strength varies over orders of magnitude; opacity
                            // carries it without a second colour scale.
                            opacity={0.35 + 0.65 * (d.strength / strongest)}
                        />
                    ))}
                </svg>
            </div>
            <figcaption className="text-xs text-muted-foreground">
                <span className="font-medium text-foreground">
                    {defects.length} defect{defects.length === 1 ? '' : 's'}
                </span>{' '}
                — ringed on the detail map. Rings following the artwork mean the
                frames did not align, not that the card is scratched.
            </figcaption>
        </figure>
    );
}
