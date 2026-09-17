import { Head, Link } from '@inertiajs/react';
import { Pause, Play, RotateCcw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Card = {
    id: number;
    name: string;
    number: string | null;
    set: string | null;
    image: string | null;
    href: string | null;
};

type Bar = { id: number; value: number; sales: number };
type Frame = { day: string; volume: number; bars: Bar[] };

type Props = {
    race: {
        title: string;
        description?: string | null;
        owner?: string | null;
        slug?: string | null;
        editable?: boolean;
        cards: Record<string, Card>;
        frames: Frame[];
        volume: { day: string; sales: number }[];
        window: number;
        step: number;
        capped?: boolean;
        considered?: number;
    };
};

/** Milliseconds per day of the race. Slow enough to read a name. */
const FRAME_MS = 900;

const ROW_H = 34;

function dayLabel(day: string): string {
    return new Date(day + 'T00:00:00').toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

/**
 * The volume ribbon. Spans every day we have sales for, including the fortnight
 * before the race starts — that ramp is the release itself, and it happens
 * before any single card has enough sales to be worth plotting.
 */
function VolumeRibbon({
    volume,
    activeDay,
}: {
    volume: { day: string; sales: number }[];
    activeDay: string;
}) {
    const peak = Math.max(...volume.map((v) => v.sales), 1);

    return (
        <div className="flex h-16 items-end gap-px" aria-hidden>
            {volume.map((v) => (
                <div
                    key={v.day}
                    className={cn(
                        'flex-1 rounded-t-sm transition-colors',
                        v.day <= activeDay ? 'bg-primary/70' : 'bg-muted',
                    )}
                    style={{
                        height: `${Math.max(2, (v.sales / peak) * 100)}%`,
                    }}
                    title={`${dayLabel(v.day)} — ${v.sales} sales`}
                />
            ))}
        </div>
    );
}

export default function PriceRace({ race }: Props) {
    const { frames, cards, volume } = race;

    const [index, setIndex] = useState(0);
    const [playing, setPlaying] = useState(true);
    const timer = useRef<number | null>(null);

    const frame = frames[index];

    // One scale for the whole race, so a bar's length means the same thing on
    // every frame. Rescaling per frame would draw a falling market as flat.
    const scale = useMemo(
        () => Math.max(...frames.flatMap((f) => f.bars.map((b) => b.value)), 1),
        [frames],
    );

    // The tallest the field ever gets. Fixing this at thirty was wrong in both
    // directions: a race can be built with up to fifty bars, which overflowed
    // whatever followed, and a race of four cards left a gap the size of the
    // twenty-six it did not have.
    const rows = useMemo(
        () => Math.max(...frames.map((f) => f.bars.length), 1),
        [frames],
    );

    useEffect(() => {
        if (!playing) {
            return;
        }

        timer.current = window.setTimeout(() => {
            setIndex((i) => {
                if (i + 1 >= frames.length) {
                    setPlaying(false);

                    return i;
                }

                return i + 1;
            });
        }, FRAME_MS);

        return () => {
            if (timer.current) {
                window.clearTimeout(timer.current);
            }
        };
    }, [playing, index, frames.length]);

    const restart = useCallback(() => {
        setIndex(0);
        setPlaying(true);
    }, []);

    const atEnd = index + 1 >= frames.length;

    return (
        <>
            <Head title={`${race.title} — price race`} />

            <div className="mx-auto w-full max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Price race
                        </p>
                        <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                            {race.title}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {race.description ??
                                'Real sold prices, in order, as they moved.'}{' '}
                            Each frame is a {race.window}-day median, because
                            one sale is not a price.
                            {race.step > 1 &&
                                ` One frame per ${race.step} days.`}
                        </p>
                        {race.owner && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                by {race.owner}
                            </p>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                atEnd ? restart() : setPlaying((p) => !p)
                            }
                        >
                            {atEnd ? (
                                <RotateCcw className="size-4" />
                            ) : playing ? (
                                <Pause className="size-4" />
                            ) : (
                                <Play className="size-4" />
                            )}
                            {atEnd ? 'Replay' : playing ? 'Pause' : 'Play'}
                        </Button>
                        <span className="w-24 text-right font-mono text-sm tabular-nums">
                            {dayLabel(frame.day)}
                        </span>
                    </div>
                </div>

                <input
                    type="range"
                    min={0}
                    max={frames.length - 1}
                    value={index}
                    onChange={(e) => {
                        setPlaying(false);
                        setIndex(Number(e.target.value));
                    }}
                    aria-label="Day"
                    className="mt-6 w-full accent-primary"
                />

                <div className="mt-2">
                    <VolumeRibbon volume={volume} activeDay={frame.day} />
                    <p className="mt-1 text-xs text-muted-foreground">
                        Sales per day —{' '}
                        <span className="font-semibold text-foreground">
                            {frame.volume.toLocaleString()}
                        </span>{' '}
                        on {dayLabel(frame.day)}
                    </p>
                </div>

                {/*
                    Rows are absolutely positioned and animate to their new rank,
                    so overtaking reads as movement rather than as a redraw.
                */}
                <div className="relative mt-8" style={{ height: rows * ROW_H }}>
                    {frame.bars.map((bar, rank) => {
                        const card = cards[String(bar.id)];

                        if (!card) {
                            return null;
                        }

                        return (
                            <div
                                key={bar.id}
                                className="absolute inset-x-0 flex items-center gap-2 transition-all duration-700 ease-out"
                                style={{
                                    transform: `translateY(${rank * ROW_H}px)`,
                                    height: ROW_H - 6,
                                }}
                            >
                                <span className="w-5 shrink-0 text-right font-mono text-xs text-muted-foreground tabular-nums">
                                    {rank + 1}
                                </span>

                                <div
                                    className="flex h-full min-w-0 items-center gap-2 rounded-r-md bg-primary/15 pr-2 transition-all duration-700 ease-out"
                                    style={{
                                        width: `${Math.max(12, (bar.value / scale) * 100)}%`,
                                    }}
                                >
                                    {card.image && (
                                        <img
                                            src={card.image}
                                            alt=""
                                            className="h-full w-auto rounded-sm object-cover"
                                            loading="lazy"
                                        />
                                    )}
                                    <span className="truncate text-xs font-medium">
                                        {card.href ? (
                                            <Link
                                                href={card.href}
                                                className="hover:underline"
                                            >
                                                {card.name}
                                            </Link>
                                        ) : (
                                            card.name
                                        )}
                                    </span>
                                </div>

                                <span className="shrink-0 font-mono text-xs font-semibold tabular-nums">
                                    {formatMoney(bar.value)}
                                </span>
                                <span className="hidden w-16 shrink-0 text-xs text-muted-foreground tabular-nums sm:inline">
                                    {bar.sales} sold
                                </span>
                            </div>
                        );
                    })}
                </div>

                <div className="mt-8 space-y-2 text-xs text-muted-foreground">
                    <p>
                        Built from completed sales only, never asking prices.
                        eBay dates a sold listing without a time, so a day is
                        the finest honest resolution here.
                    </p>
                    {race.capped && (
                        <p>
                            {race.considered?.toLocaleString()} cards matched
                            this selection. The race runs the most valuable that
                            actually trade — a card has to sell to have a price,
                            and the least liquid would sit still for the whole
                            tape.
                        </p>
                    )}
                    <p className="flex flex-wrap gap-3 pt-1">
                        <Link
                            href="/races"
                            className="font-semibold text-primary hover:underline"
                        >
                            All races &rarr;
                        </Link>
                        {race.editable && race.slug && (
                            <Link
                                href={`/races/${race.slug}/edit`}
                                className="font-semibold text-primary hover:underline"
                            >
                                Edit this race
                            </Link>
                        )}
                    </p>
                </div>
            </div>
        </>
    );
}
