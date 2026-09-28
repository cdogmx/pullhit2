import { Head, router } from '@inertiajs/react';
import { ArrowRight, Check, TrendingDown, TrendingUp, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type CardRef = { id: number; label: string; set: string | null } | null;

type Finding = {
    id: number;
    price: number;
    title: string;
    ratio: number | null;
    stale: boolean;
    filed_under: CardRef;
    reads_as: CardRef;
};

type Snapshot = {
    compared: number;
    buckets: Record<string, number>;
    over_2x: number;
    under_half: number;
    taken_at: string | null;
};

type Props = {
    latest: Snapshot | null;
    trend: {
        taken_at: string | null;
        compared: number;
        over_2x: number;
        under_half: number;
    }[];
    findings: {
        data: Finding[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    open: number;
};

const money = (cents: number) =>
    `$${(cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * The spread, as a row of proportional bars.
 *
 * Deliberately not a chart library: the shape is the whole message — a tall pile
 * near 1x with thin tails is a healthy catalog, and a fat upper tail means slab
 * or lot money is pricing raw cards somewhere we have not looked.
 */
function Distribution({ snapshot }: { snapshot: Snapshot }) {
    const entries = Object.entries(snapshot.buckets);
    const max = Math.max(...entries.map(([, n]) => n), 1);

    return (
        <div className="flex flex-col gap-2">
            {entries.map(([label, n]) => {
                const share = snapshot.compared ? (n / snapshot.compared) * 100 : 0;
                const healthy = label === '0.5–2x';

                return (
                    <div key={label} className="flex items-center gap-3 text-sm">
                        <span className="w-24 shrink-0 text-muted-foreground">
                            {label}
                        </span>
                        <div className="h-4 flex-1 overflow-hidden rounded bg-muted">
                            <div
                                className={cn(
                                    'h-full rounded',
                                    healthy ? 'bg-[#047857]' : 'bg-amber-500',
                                )}
                                style={{ width: `${(n / max) * 100}%` }}
                            />
                        </div>
                        <span className="w-28 shrink-0 text-right tabular-nums">
                            {n.toLocaleString()}
                            <span className="ml-1 text-muted-foreground">
                                ({share.toFixed(1)}%)
                            </span>
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

function FindingRow({ finding }: { finding: Finding }) {
    const [busy, setBusy] = useState(false);

    const act = (verb: 'apply' | 'dismiss') => {
        setBusy(true);
        router.post(
            `/admin/price-health/${finding.id}/${verb}`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Done'),
                onError: (e) =>
                    toast.error(Object.values(e)[0] ?? 'That did not work'),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Card>
            <CardContent className="flex flex-col gap-3 pt-6 lg:flex-row lg:items-start">
                <div className="flex min-w-0 flex-1 flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium tabular-nums">
                            {money(finding.price)}
                        </span>
                        {finding.ratio != null && (
                            <Badge variant="secondary" className="tabular-nums">
                                card is {finding.ratio}x off
                            </Badge>
                        )}
                        {finding.stale && (
                            <Badge variant="outline">
                                comp already removed
                            </Badge>
                        )}
                    </div>

                    <p className="text-sm break-words text-muted-foreground">
                        {finding.title}
                    </p>

                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="text-muted-foreground">filed under</span>
                        <span className="font-medium">
                            {finding.filed_under?.label ?? '—'}
                        </span>
                        {finding.filed_under?.set && (
                            <span className="text-xs text-muted-foreground">
                                {finding.filed_under.set}
                            </span>
                        )}
                        <ArrowRight className="size-3.5 text-muted-foreground" />
                        <span className="text-muted-foreground">reads as</span>
                        <span className="font-medium">
                            {finding.reads_as?.label ?? '—'}
                        </span>
                        {finding.reads_as?.set && (
                            <span className="text-xs text-muted-foreground">
                                {finding.reads_as.set}
                            </span>
                        )}
                    </div>
                </div>

                <div className="flex gap-2 lg:w-64 lg:shrink-0">
                    <Button
                        size="sm"
                        variant="outline"
                        className="flex-1"
                        disabled={busy || finding.stale}
                        onClick={() => act('apply')}
                        title={
                            finding.stale
                                ? 'Another pass already removed this comp'
                                : 'Remove this comp and reprice the card'
                        }
                    >
                        <Check className="size-4" />
                        Remove comp
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        className="flex-1"
                        disabled={busy}
                        onClick={() => act('dismiss')}
                        title="The model was wrong — keep the comp"
                    >
                        <X className="size-4" />
                        Keep
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export default function PriceHealth({ latest, trend, findings, open }: Props) {
    const first = trend[0];
    const last = trend[trend.length - 1];
    const movement =
        first && last && trend.length > 1 ? last.over_2x - first.over_2x : null;

    return (
        <>
            <Head title="Price health" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <p className="text-xs text-muted-foreground">Admin</p>
                    <h1 className="text-2xl font-bold tracking-tight">
                        Price health
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                        How far our raw prices sit from PriceCharting&rsquo;s, and
                        what the nightly AI pass made of the comps behind the worst
                        of them. PriceCharting is the comparison because nothing we
                        compute feeds it &mdash; every pricing bug found so far was
                        invisible from the inside.
                    </p>
                </div>

                {latest ? (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
                            <CardTitle className="text-sm">
                                {latest.compared.toLocaleString()} cards compared
                                {latest.taken_at
                                    ? ` · ${new Date(latest.taken_at).toLocaleDateString()}`
                                    : ''}
                            </CardTitle>
                            {movement != null && (
                                <span
                                    className={cn(
                                        'flex items-center gap-1 text-sm',
                                        movement > 0
                                            ? 'text-[#b91c1c] dark:text-red-400'
                                            : 'text-[#047857] dark:text-emerald-400',
                                    )}
                                >
                                    {movement > 0 ? (
                                        <TrendingUp className="size-4" />
                                    ) : (
                                        <TrendingDown className="size-4" />
                                    )}
                                    {movement > 0 ? '+' : ''}
                                    {movement} over 2x since{' '}
                                    {first?.taken_at ?? 'the first reading'}
                                </span>
                            )}
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <Distribution snapshot={latest} />
                            <p className="text-sm">
                                <span className="font-medium">
                                    {latest.over_2x.toLocaleString()}
                                </span>{' '}
                                over-priced by 2x or more ·{' '}
                                <span className="font-medium">
                                    {latest.under_half.toLocaleString()}
                                </span>{' '}
                                under half. Both tails cost money: the upper one is
                                slab or lot money pricing a raw card, the lower one
                                is usually a value left behind after its comps were
                                pruned.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="py-8 text-center text-sm text-muted-foreground">
                            No reading yet. It is taken nightly by{' '}
                            <code className="rounded bg-muted px-1 py-0.5">
                                valuation:price-divergence
                            </code>
                            , which needs the scheduler running.
                        </CardContent>
                    </Card>
                )}

                <div>
                    <h2 className="text-lg font-semibold">
                        Comps the AI pass questioned
                        {open > 0 && (
                            <Badge className="ml-2">{open} open</Badge>
                        )}
                    </h2>
                    <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                        A model read the listing title, the ordinary catalog matcher
                        placed what it read, and the two disagreed with where the
                        comp is filed. That is worth a look, not an automatic
                        deletion &mdash; removing a comp removes a real sale, so it
                        is your call. A repeated shape here is better fixed in the
                        classifier, where it costs nothing and stays fixed.
                    </p>
                </div>

                {findings.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-sm text-muted-foreground">
                            Nothing open. Either the pass found no disagreements or
                            it has not run yet.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="flex flex-col gap-3">
                        {findings.data.map((f) => (
                            <FindingRow key={f.id} finding={f} />
                        ))}
                    </div>
                )}

                {findings.links.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {findings.links.map((link, i) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
