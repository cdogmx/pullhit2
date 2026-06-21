import { Head, Link } from '@inertiajs/react';
import { ScanLine } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cardHref, relativeTime } from '@/lib/format';
import type { AdminPagination } from '@/types';

type ScanResult = {
    name: string | null;
    number: string | null;
    source: 'cache' | 'vision';
    match: {
        id: number | null;
        name: string | null;
        number: string | null;
        set: string | null;
        image_url: string | null;
        url: string | null;
    } | null;
};

type Scan = {
    id: number;
    mode: 'single' | 'bulk';
    image_url: string | null;
    card_count: number;
    ai_reads: number;
    cache_hits: number;
    results: ScanResult[];
    created_at: string | null;
};

type Props = { scans: Scan[]; pagination: AdminPagination };

export default function ScanHistory({ scans, pagination }: Props) {
    return (
        <>
            <Head title="Scan history" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-3">
                    <h1 className="text-xl font-bold tracking-tight">
                        Scan history
                    </h1>
                    <Button asChild variant="outline" size="sm">
                        <Link href="/scan">
                            <ScanLine className="size-4" /> New scan
                        </Link>
                    </Button>
                </div>

                {scans.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-sm text-muted-foreground">
                            No scans yet.{' '}
                            <Link
                                href="/scan"
                                className="font-medium text-primary hover:underline"
                            >
                                Scan a card →
                            </Link>
                        </CardContent>
                    </Card>
                ) : (
                    scans.map((scan) => <ScanCard key={scan.id} scan={scan} />)
                )}

                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            disabled={pagination.page <= 1}
                        >
                            <Link
                                href={`/scan/history?page=${pagination.page - 1}`}
                                preserveScroll
                            >
                                Previous
                            </Link>
                        </Button>
                        <span className="text-muted-foreground">
                            Page {pagination.page} of {pagination.last_page}
                        </span>
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            disabled={pagination.page >= pagination.last_page}
                        >
                            <Link
                                href={`/scan/history?page=${pagination.page + 1}`}
                                preserveScroll
                            >
                                Next
                            </Link>
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

function ScanCard({ scan }: { scan: Scan }) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-4 pt-6 sm:flex-row">
                <div className="shrink-0">
                    {scan.image_url && (
                        <img
                            src={scan.image_url}
                            alt="Scanned photo"
                            className="h-40 w-auto rounded-md border border-border object-contain"
                            loading="lazy"
                        />
                    )}
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        <span className="capitalize">{scan.mode}</span> ·{' '}
                        {scan.card_count} card
                        {scan.card_count === 1 ? '' : 's'} ·{' '}
                        {relativeTime(scan.created_at)}
                    </p>
                </div>

                <div className="min-w-0 flex-1 space-y-2">
                    {scan.results.map((r, i) => (
                        <ResultRow key={i} result={r} />
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

function ResultRow({ result }: { result: ScanResult }) {
    const m = result.match;
    const inner = (
        <div className="flex items-center gap-2">
            <div className="h-12 w-9 shrink-0 overflow-hidden rounded bg-muted">
                {m?.image_url && (
                    <img
                        src={m.image_url}
                        alt=""
                        className="size-full object-contain"
                        loading="lazy"
                    />
                )}
            </div>
            <div className="min-w-0">
                <p className="truncate text-sm font-medium">
                    {m?.name ?? result.name ?? 'Unknown card'}
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    {(m?.number ?? result.number) ?? ''}
                    {m?.set ? ` · ${m.set}` : ''}
                </p>
            </div>
            <Badge
                variant={result.source === 'cache' ? 'secondary' : 'outline'}
                className="ml-auto shrink-0 text-[10px]"
            >
                {result.source === 'cache' ? 'Cache' : 'AI'}
            </Badge>
        </div>
    );

    return m?.id ? (
        <Link
            href={cardHref(m)}
            className="block rounded-md p-1.5 transition-colors hover:bg-accent/50"
        >
            {inner}
        </Link>
    ) : (
        <div className="p-1.5">{inner}</div>
    );
}

ScanHistory.layout = {
    breadcrumbs: [
        { title: 'Scan', href: '/scan' },
        { title: 'History', href: '/scan/history' },
    ],
};
