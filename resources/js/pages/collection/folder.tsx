import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Copy, Folder, Globe, Lock } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { HoldingsTable } from '@/components/collection/holdings-table';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatMoney } from '@/lib/format';
import type { GradingCompanyOption, Holding, PortfolioSummary } from '@/types';

type Props = {
    collection: {
        id: number;
        name: string;
        slug: string;
        is_default: boolean;
    };
    folder: {
        id: number;
        name: string;
        slug: string;
        is_public: boolean;
        public_url: string | null;
    };
    holdings: Holding[];
    summary: PortfolioSummary;
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

function formatGain(cents: number | null, currency = 'USD'): string {
    if (cents == null) {
        return '—';
    }

    const sign = cents > 0 ? '+' : cents < 0 ? '−' : '';

    return `${sign}${formatMoney(Math.abs(cents), currency)}`;
}

export default function CollectionFolderPage({
    collection,
    folder,
    holdings,
    summary,
    gradingCompanies,
}: Props) {
    const c = summary.currency;
    const collectionHref = collection.is_default
        ? '/collection'
        : `/collection?collection=${collection.slug}`;

    const [copied, setCopied] = useState(false);

    const togglerPublic = () =>
        router.patch(
            `/collection/folders/${folder.id}`,
            { is_public: !folder.is_public },
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        folder.is_public
                            ? 'Folder is now private.'
                            : 'Folder is now public.',
                    ),
            },
        );

    const copyLink = () => {
        if (!folder.public_url) {
            return;
        }

        navigator.clipboard.writeText(folder.public_url).then(() => {
            setCopied(true);
            toast.success('Folder link copied.');
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <>
            <Head title={`${folder.name} · ${collection.name}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                {/* Breadcrumb — makes the collection → folder hierarchy explicit. */}
                <nav className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                    <Link href="/collection" className="hover:text-foreground">
                        Collections
                    </Link>
                    <ChevronRight className="size-3" />
                    <Link href={collectionHref} className="hover:text-foreground">
                        {collection.name}
                    </Link>
                    <ChevronRight className="size-3" />
                    <span className="font-medium text-foreground">
                        {folder.name}
                    </span>
                </nav>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
                            <Folder className="size-6 text-muted-foreground" />
                            {folder.name}
                        </h1>
                        <p className="text-xs text-muted-foreground">
                            A folder in{' '}
                            <Link
                                href={collectionHref}
                                className="underline-offset-4 hover:text-foreground hover:underline"
                            >
                                {collection.name}
                            </Link>
                        </p>
                    </div>

                    <div className="flex items-center gap-1.5">
                        {folder.is_public && folder.public_url && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={copyLink}
                                className="h-8"
                            >
                                <Copy className="size-3.5" />
                                {copied ? 'Copied' : 'Copy link'}
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant={folder.is_public ? 'secondary' : 'outline'}
                            onClick={togglerPublic}
                            className="h-8"
                        >
                            {folder.is_public ? (
                                <>
                                    <Globe className="size-3.5" /> Public
                                </>
                            ) : (
                                <>
                                    <Lock className="size-3.5" /> Private
                                </>
                            )}
                        </Button>
                    </div>
                </div>

                {/* Folder-scoped summary */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-xs text-muted-foreground">
                                Folder value
                            </p>
                            <p className="mt-1 text-2xl font-bold tracking-tight">
                                {formatMoney(summary.total_value, c)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-xs text-muted-foreground">
                                Unrealized P&L
                            </p>
                            <p
                                className={`mt-1 text-2xl font-bold tracking-tight ${gainClass(summary.unrealized_gain)}`}
                            >
                                {formatGain(summary.unrealized_gain, c)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-xs text-muted-foreground">Cards</p>
                            <p className="mt-1 text-2xl font-bold tracking-tight">
                                {summary.card_count}
                                <span className="ml-1 text-sm font-normal text-muted-foreground">
                                    in {summary.item_count}{' '}
                                    {summary.item_count === 1
                                        ? 'holding'
                                        : 'holdings'}
                                </span>
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {holdings.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <p>This folder is empty.</p>
                            <p className="mt-1 text-sm">
                                File a card into “{folder.name}” from its page or the
                                edit menu to add it here.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <HoldingsTable
                        holdings={holdings}
                        gradingCompanies={gradingCompanies}
                        otherCollections={[]}
                    />
                )}
            </div>
        </>
    );
}

CollectionFolderPage.layout = {
    breadcrumbs: [{ title: 'Collection', href: '/collection' }],
};
