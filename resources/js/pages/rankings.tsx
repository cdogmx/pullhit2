import { Head, Link } from '@inertiajs/react';
import { Award, Gift, Trophy } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type AllTimeRow = {
    rank: number;
    username: string | null;
    points: number;
    level: string;
};
type MonthlyRow = { rank: number; username: string | null; entries: number };

type Me = {
    username: string | null;
    points: number;
    entries: number;
    rank: number | null;
    level: { name: string; to_next: number | null; next_at: number | null };
} | null;

type Giveaway = {
    title: string;
    prize: string;
    image: string | null;
    description: string | null;
    period_label: string;
    total_entries: number;
    my_entries: number;
} | null;

type PastWinner = {
    period_label: string;
    prize: string;
    image: string | null;
    winner: string | null;
};

type Props = {
    allTime: AllTimeRow[];
    monthly: MonthlyRow[];
    month: string;
    me: Me;
    giveaway: Giveaway;
    pastWinners: PastWinner[];
};

function medal(rank: number): string {
    return rank === 1
        ? 'text-amber-500'
        : rank === 2
          ? 'text-zinc-400'
          : rank === 3
            ? 'text-amber-700'
            : 'text-muted-foreground';
}

export default function Rankings({
    allTime,
    monthly,
    month,
    me,
    giveaway,
    pastWinners,
}: Props) {
    return (
        <>
            <Head title="Community rankings" />
            <div className="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        Community rankings
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Earn points by improving the catalog — report missing
                        cards &amp; sets and suggest fixes. Points earned this
                        month are your entries for{' '}
                        <span className="font-medium text-foreground">
                            {month}
                        </span>
                        ’s giveaway.
                    </p>
                </div>

                {/* This month's giveaway + the viewer's entries */}
                {giveaway && (
                    <Card className="mb-6 border-primary/40 bg-primary/5">
                        <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
                            <div className="flex items-start gap-3">
                                {giveaway.image ? (
                                    <img
                                        src={giveaway.image}
                                        alt={giveaway.prize}
                                        className="size-16 shrink-0 rounded-xl border border-border bg-background object-contain"
                                    />
                                ) : (
                                    <span className="flex size-11 items-center justify-center rounded-xl bg-primary/15 text-primary">
                                        <Gift className="size-5" />
                                    </span>
                                )}
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-primary">
                                        {giveaway.period_label} giveaway
                                    </p>
                                    <p className="text-lg font-bold">
                                        {giveaway.prize}
                                    </p>
                                    {giveaway.description && (
                                        <p className="mt-0.5 max-w-md text-sm text-muted-foreground">
                                            {giveaway.description}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="flex items-center gap-4 rounded-xl bg-background px-4 py-3 text-center">
                                <div>
                                    <p className="text-xl font-bold text-primary">
                                        {giveaway.my_entries.toLocaleString()}
                                    </p>
                                    <p className="text-[11px] text-muted-foreground">
                                        your entries
                                    </p>
                                </div>
                                <div className="h-8 w-px bg-border" />
                                <div>
                                    <p className="text-xl font-bold">
                                        {giveaway.total_entries.toLocaleString()}
                                    </p>
                                    <p className="text-[11px] text-muted-foreground">
                                        total pool
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* The viewer's standing */}
                {me && (
                    <Card className="mb-6 border-primary/30 bg-primary/5">
                        <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
                            <div className="flex items-center gap-3">
                                <span className="flex size-10 items-center justify-center rounded-lg bg-primary/15 text-primary">
                                    <Award className="size-5" />
                                </span>
                                <div>
                                    <p className="text-sm font-semibold">
                                        {me.username
                                            ? `@${me.username}`
                                            : 'You'}{' '}
                                        · {me.level.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {me.points.toLocaleString()} pts
                                        {me.rank
                                            ? ` · #${me.rank} all-time`
                                            : ''}
                                        {me.level.to_next != null &&
                                            ` · ${me.level.to_next} to next level`}
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2 rounded-lg bg-background px-3 py-2 text-sm">
                                <Gift className="size-4 text-primary" />
                                <span className="font-semibold">
                                    {me.entries.toLocaleString()}
                                </span>
                                <span className="text-muted-foreground">
                                    {month} entries
                                </span>
                            </div>
                            <Button asChild size="sm">
                                <Link href="/contribute">Contribute</Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* This month (giveaway entries) */}
                    <Board
                        title={`${month} — giveaway entries`}
                        icon={<Gift className="size-4 text-primary" />}
                        empty="No entries yet this month. Be the first!"
                        rows={monthly}
                        valueLabel="entries"
                        value={(r) => (r as MonthlyRow).entries}
                    />

                    {/* All-time */}
                    <Board
                        title="All-time leaders"
                        icon={<Trophy className="size-4 text-amber-500" />}
                        empty="No contributors yet."
                        rows={allTime}
                        valueLabel="pts"
                        value={(r) => (r as AllTimeRow).points}
                        sub={(r) => (r as AllTimeRow).level}
                    />
                </div>

                {pastWinners.length > 0 && (
                    <div className="mt-8">
                        <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold">
                            <Trophy className="size-4 text-amber-500" />
                            Past winners
                        </h2>
                        <div className="overflow-hidden rounded-xl border border-border">
                            <table className="w-full text-sm">
                                <tbody>
                                    {pastWinners.map((w, i) => (
                                        <tr
                                            key={i}
                                            className="border-b border-border/60 last:border-0"
                                        >
                                            <td className="px-3 py-2 font-medium">
                                                {w.winner
                                                    ? `@${w.winner}`
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-2 text-muted-foreground">
                                                <span className="flex items-center gap-2">
                                                    {w.image && (
                                                        <img
                                                            src={w.image}
                                                            alt=""
                                                            className="size-8 shrink-0 rounded border border-border bg-background object-contain"
                                                        />
                                                    )}
                                                    {w.prize}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 text-right text-xs text-muted-foreground">
                                                {w.period_label}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

function Board({
    title,
    icon,
    empty,
    rows,
    valueLabel,
    value,
    sub,
}: {
    title: string;
    icon: React.ReactNode;
    empty: string;
    rows: (AllTimeRow | MonthlyRow)[];
    valueLabel: string;
    value: (r: AllTimeRow | MonthlyRow) => number;
    sub?: (r: AllTimeRow | MonthlyRow) => string;
}) {
    return (
        <Card>
            <CardContent className="pt-6">
                <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold">
                    {icon}
                    {title}
                </h2>
                {rows.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        {empty}
                    </p>
                ) : (
                    <ul className="divide-y divide-border/60">
                        {rows.map((r) => (
                            <li
                                key={r.rank}
                                className="flex items-center gap-3 py-2"
                            >
                                <span
                                    className={cn(
                                        'w-6 text-center text-sm font-bold tabular-nums',
                                        medal(r.rank),
                                    )}
                                >
                                    {r.rank}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-sm font-medium">
                                    {r.username ? `@${r.username}` : 'Anonymous'}
                                </span>
                                {sub && (
                                    <Badge
                                        variant="secondary"
                                        className="text-[10px]"
                                    >
                                        {sub(r)}
                                    </Badge>
                                )}
                                <span className="text-sm font-semibold tabular-nums">
                                    {value(r).toLocaleString()}
                                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                                        {valueLabel}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
