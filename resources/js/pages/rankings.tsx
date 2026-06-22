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

type Props = {
    allTime: AllTimeRow[];
    monthly: MonthlyRow[];
    month: string;
    me: Me;
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

export default function Rankings({ allTime, monthly, month, me }: Props) {
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
