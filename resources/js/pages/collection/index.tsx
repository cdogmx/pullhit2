import { Head, Link, router } from '@inertiajs/react';
import { Download, Trash2, Upload } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    Allocation,
    Holding,
    PortfolioMover,
    PortfolioSummary,
} from '@/types';

type Props = {
    holdings: Holding[];
    summary: PortfolioSummary;
    allocation: Allocation[];
    gainers: PortfolioMover[];
    decliners: PortfolioMover[];
    publicUrl: string | null;
};

const gainClass = (n: number | null | undefined) =>
    n == null
        ? 'text-muted-foreground'
        : n > 0
          ? 'text-emerald-600 dark:text-emerald-400'
          : n < 0
            ? 'text-red-600 dark:text-red-400'
            : 'text-muted-foreground';

/** Signed money, e.g. "+$12.50" / "−$4.00"; "—" when null. */
function formatGain(cents: number | null, currency = 'USD'): string {
    if (cents == null) {
        return '—';
    }

    const sign = cents > 0 ? '+' : cents < 0 ? '−' : '';

    return `${sign}${formatMoney(Math.abs(cents), currency)}`;
}

export default function CollectionIndex({
    holdings,
    summary,
    allocation,
    gainers,
    decliners,
    publicUrl,
}: Props) {
    const c = summary.currency;

    return (
        <>
            <Head title="Collection" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Your collection
                        </h1>
                        {publicUrl ? (
                            <a
                                href={publicUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            >
                                Public · {publicUrl.replace(/^https?:\/\//, '')}
                            </a>
                        ) : (
                            <Link
                                href="/settings/profile"
                                className="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            >
                                Private · make it public
                            </Link>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href="/collection/import">
                                <Upload className="size-4" />
                                Import
                            </Link>
                        </Button>
                        {holdings.length > 0 && (
                            <Button asChild variant="outline" size="sm">
                                <a href="/collection/export">
                                    <Download className="size-4" />
                                    Export CSV
                                </a>
                            </Button>
                        )}
                    </div>
                </div>

                {/* Summary */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard label="Portfolio value">
                        {formatMoney(summary.total_value, c)}
                    </SummaryCard>
                    <SummaryCard label="Cost basis">
                        {formatMoney(summary.total_cost, c)}
                    </SummaryCard>
                    <SummaryCard label="Unrealized P&L">
                        <span className={gainClass(summary.unrealized_gain)}>
                            {formatGain(summary.unrealized_gain, c)}
                            {summary.unrealized_pct != null && (
                                <span className="ml-1 text-sm font-normal">
                                    ({summary.unrealized_pct > 0 ? '+' : ''}
                                    {summary.unrealized_pct}%)
                                </span>
                            )}
                        </span>
                    </SummaryCard>
                    <SummaryCard label="Cards">
                        {summary.card_count}
                        <span className="ml-1 text-sm font-normal text-muted-foreground">
                            in {summary.item_count}{' '}
                            {summary.item_count === 1 ? 'holding' : 'holdings'}
                        </span>
                    </SummaryCard>
                </div>

                {holdings.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <p>Your collection is empty.</p>
                            <Link
                                href="/browse"
                                className="mt-2 inline-block text-sm font-medium text-primary hover:underline"
                            >
                                Browse the catalog to add cards →
                            </Link>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {/* Allocation + movers */}
                        <div className="grid gap-4 lg:grid-cols-3">
                            <Card className="lg:col-span-1">
                                <CardHeader>
                                    <CardTitle className="text-sm">Allocation by set</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {allocation.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            No valued holdings yet.
                                        </p>
                                    )}
                                    {allocation.map((a) => (
                                        <div key={a.label}>
                                            <div className="flex justify-between text-sm">
                                                <span className="truncate">{a.label}</span>
                                                <span className="text-muted-foreground">
                                                    {formatMoney(a.value, c)} · {a.pct}%
                                                </span>
                                            </div>
                                            <div className="mt-1 h-1.5 rounded-full bg-muted">
                                                <div
                                                    className="h-1.5 rounded-full bg-primary"
                                                    style={{ width: `${a.pct}%` }}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>

                            <MoversCard title="Top gainers" movers={gainers} currency={c} />
                            <MoversCard title="Top decliners" movers={decliners} currency={c} />
                        </div>

                        {/* Holdings */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">Holdings</CardTitle>
                            </CardHeader>
                            <CardContent className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-left text-xs text-muted-foreground">
                                        <tr className="border-b border-border">
                                            <th className="py-2 pr-3 font-medium">Card</th>
                                            <th className="py-2 pr-3 font-medium">State</th>
                                            <th className="py-2 pr-3 text-right font-medium">Qty</th>
                                            <th className="py-2 pr-3 text-right font-medium">
                                                Avg cost
                                            </th>
                                            <th className="py-2 pr-3 text-right font-medium">
                                                Cost basis
                                            </th>
                                            <th className="py-2 pr-3 text-right font-medium">
                                                Value
                                            </th>
                                            <th className="py-2 pr-3 text-right font-medium">
                                                P&L
                                            </th>
                                            <th className="py-2" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {holdings.map((h) => (
                                            <tr
                                                key={h.id}
                                                className="border-b border-border/60 last:border-0"
                                            >
                                                <td className="py-2 pr-3">
                                                    <div className="flex items-center gap-2">
                                                        {h.catalog_item?.image_url && (
                                                            <img
                                                                src={h.catalog_item.image_url}
                                                                alt=""
                                                                className="h-10 w-auto rounded"
                                                                loading="lazy"
                                                            />
                                                        )}
                                                        <div className="min-w-0">
                                                            {h.catalog_item ? (
                                                                <Link
                                                                    href={`/catalog/${h.catalog_item.id}`}
                                                                    className="font-medium hover:underline"
                                                                >
                                                                    {h.catalog_item.name}
                                                                </Link>
                                                            ) : (
                                                                <span className="font-medium">
                                                                    Unknown
                                                                </span>
                                                            )}
                                                            <p className="text-xs text-muted-foreground">
                                                                {h.catalog_item?.number}
                                                                {h.catalog_item?.set
                                                                    ? ` · ${h.catalog_item.set.name}`
                                                                    : ''}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="py-2 pr-3">
                                                    <Badge variant="secondary">
                                                        {h.state_label}
                                                    </Badge>
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    {h.quantity}
                                                </td>
                                                <td className="py-2 pr-3 text-right text-muted-foreground">
                                                    {formatMoney(
                                                        h.quantity > 0
                                                            ? Math.round(
                                                                  h.cost_basis / h.quantity,
                                                              )
                                                            : 0,
                                                        h.currency,
                                                    )}
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    {formatMoney(h.cost_basis, h.currency)}
                                                </td>
                                                <td className="py-2 pr-3 text-right font-medium">
                                                    {formatMoney(h.market_value, h.currency)}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'py-2 pr-3 text-right',
                                                        gainClass(h.unrealized_gain),
                                                    )}
                                                >
                                                    {formatGain(h.unrealized_gain, h.currency)}
                                                </td>
                                                <td className="py-2 text-right">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.delete(
                                                                `/collection/${h.id}`,
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        className="text-muted-foreground hover:text-red-600"
                                                        aria-label="Remove holding"
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </>
    );
}

function SummaryCard({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <Card>
            <CardContent className="pt-6">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="mt-1 text-2xl font-bold tracking-tight">{children}</p>
            </CardContent>
        </Card>
    );
}

function MoversCard({
    title,
    movers,
    currency,
}: {
    title: string;
    movers: PortfolioMover[];
    currency: string;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm">{title}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
                {movers.length === 0 && (
                    <p className="text-sm text-muted-foreground">Nothing yet.</p>
                )}
                {movers.map((m) => (
                    <div key={m.id} className="flex items-center justify-between text-sm">
                        <span className="min-w-0 truncate">
                            {m.name}{' '}
                            <span className="text-xs text-muted-foreground">{m.state}</span>
                        </span>
                        <span className={cn('shrink-0', gainClass(m.gain))}>
                            {formatGain(m.gain, currency)}
                            {m.pct != null && (
                                <span className="ml-1 text-xs">
                                    ({m.pct > 0 ? '+' : ''}
                                    {m.pct}%)
                                </span>
                            )}
                        </span>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

CollectionIndex.layout = {
    breadcrumbs: [{ title: 'Collection', href: '/collection' }],
};
