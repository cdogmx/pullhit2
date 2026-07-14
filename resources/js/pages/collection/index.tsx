import { Head, Link, router } from '@inertiajs/react';
import { Download, Globe, Lock, Upload } from 'lucide-react';
import { toast } from 'sonner';
import { CollectionFolders } from '@/components/collection/collection-folders';
import { HoldingsTable } from '@/components/collection/holdings-table';
import type { FolderRow } from '@/components/collection/holdings-table';
import { ListTabs } from '@/components/shared/list-tabs';
import type { ListSummary } from '@/components/shared/list-tabs';
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
    GradingCompanyOption,
    Holding,
    PortfolioMover,
    PortfolioSummary,
} from '@/types';

export type { FolderRow };

type Props = {
    collections: ListSummary[];
    activeCollection: string;
    collectionLimit: number | null;
    holdings: Holding[];
    summary: PortfolioSummary;
    allocation: Allocation[];
    gainers: PortfolioMover[];
    decliners: PortfolioMover[];
    publicUrl: string | null;
    folders: FolderRow[];
    gradingCompanies: GradingCompanyOption[];
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
    collections,
    activeCollection,
    collectionLimit,
    holdings,
    summary,
    allocation,
    gainers,
    decliners,
    publicUrl,
    folders,
    gradingCompanies,
}: Props) {
    const c = summary.currency;
    const active = collections.find((x) => x.slug === activeCollection);
    const otherCollections = collections.filter(
        (x) => x.slug !== activeCollection,
    );

    const toggleActivePublic = () => {
        if (!active) {
            return;
        }

        router.patch(
            `/collections/${active.id}`,
            { is_public: !active.is_public },
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        active.is_public
                            ? 'Collection is now private.'
                            : 'Collection is now public.',
                    ),
            },
        );
    };

    return (
        <>
            <Head title="Collection" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Collection
                        </p>
                        <h1 className="text-2xl font-bold tracking-tight">
                            {active?.name ?? 'Your collection'}
                        </h1>
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            {active?.is_public && publicUrl ? (
                                <>
                                    <a
                                        href={publicUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 underline-offset-4 hover:text-foreground hover:underline"
                                    >
                                        <Globe className="size-3" />
                                        Public · {publicUrl.replace(/^https?:\/\//, '')}
                                    </a>
                                    <span aria-hidden>·</span>
                                    <button
                                        type="button"
                                        onClick={toggleActivePublic}
                                        className="underline-offset-4 hover:text-foreground hover:underline"
                                    >
                                        Make private
                                    </button>
                                </>
                            ) : (
                                <button
                                    type="button"
                                    onClick={toggleActivePublic}
                                    className="inline-flex items-center gap-1 underline-offset-4 hover:text-foreground hover:underline"
                                >
                                    <Lock className="size-3" />
                                    Private · make it public
                                </button>
                            )}
                        </div>
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

                <div>
                    <ListTabs
                        lists={collections}
                        active={activeCollection}
                        limit={collectionLimit}
                        basePath="/collection"
                        queryKey="collection"
                        entityBase="/collections"
                        noun="collection"
                        createHint="Each collection keeps its own folders."
                    />
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Switch between collections here. Folders live inside the
                        collection you're viewing.
                    </p>
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

                {holdings.length === 0 && folders.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <p>This collection is empty.</p>
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
                        {/* Allocation + movers — equal-height cards (grid stretch). */}
                        <div className="grid items-stretch gap-4 lg:grid-cols-3">
                            <Card className="flex h-full flex-col lg:col-span-1">
                                <CardHeader>
                                    <CardTitle className="text-sm">
                                        Allocation by set
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="max-h-72 flex-1 space-y-2 overflow-y-auto">
                                    {allocation.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            No valued holdings yet.
                                        </p>
                                    )}
                                    {allocation.map((a) => (
                                        <div key={a.label}>
                                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                                <span className="min-w-0 truncate">
                                                    {a.brand && (
                                                        <span className="text-muted-foreground">
                                                            {a.brand}{' '}
                                                            <span aria-hidden>
                                                                →
                                                            </span>{' '}
                                                        </span>
                                                    )}
                                                    {a.label}
                                                </span>
                                                <span className="shrink-0 text-muted-foreground">
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

                            <MoversCard
                                title="Top gainers"
                                movers={gainers}
                                currency={c}
                            />
                            <MoversCard
                                title="Top decliners"
                                movers={decliners}
                                currency={c}
                            />
                        </div>

                        <CollectionFolders
                            collectionId={active?.id ?? 0}
                            collectionName={active?.name ?? 'this collection'}
                            folders={folders}
                        />

                        <HoldingsTable
                            holdings={holdings}
                            gradingCompanies={gradingCompanies}
                            otherCollections={otherCollections.map((col) => ({
                                id: col.id,
                                name: col.name,
                            }))}
                            folders={folders}
                        />
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
        <Card className="flex h-full flex-col">
            <CardHeader>
                <CardTitle className="text-sm">{title}</CardTitle>
            </CardHeader>
            <CardContent className="max-h-72 flex-1 space-y-2 overflow-y-auto">
                {movers.length === 0 && (
                    <p className="text-sm text-muted-foreground">Nothing yet.</p>
                )}
                {movers.map((m) => (
                    <div
                        key={m.id}
                        className="flex items-center justify-between text-sm"
                    >
                        <span className="min-w-0 truncate">
                            {m.name}{' '}
                            <span className="text-xs text-muted-foreground">
                                {m.state}
                            </span>
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
