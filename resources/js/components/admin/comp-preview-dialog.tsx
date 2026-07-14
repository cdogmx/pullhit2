import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatMoney, relativeTime } from '@/lib/format';

/**
 * Admin: preview the LIVE eBay sold search for a card and see exactly which
 * listings ingestion would keep vs reject (and why). Read-only — it fires one
 * real Oxylabs lookup (honours the daily cap) but stores nothing. Mirrors the
 * classifier gate-for-gate so what you see here is what a refresh would do.
 */
type Candidate = {
    title: string;
    price: number;
    sold_at: string | null;
    seller: string | null;
    url: string | null;
    image_url: string | null;
    verdict: 'ingest' | 'reject';
    reason: string | null;
    state: string | null;
};

type Preview = {
    ok: boolean;
    message?: string;
    query: string;
    url: string;
    anchor: number;
    ingested: number;
    candidates: Candidate[];
};

export function CompPreviewDialog({
    catalogItemId,
    name,
    open,
    onOpenChange,
}: {
    catalogItemId: number;
    name: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[85vh] flex-col overflow-hidden sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>eBay comp preview</DialogTitle>
                    <DialogDescription>
                        Live sold listings for {name} — tagged as ingestion would
                        judge them. Nothing is saved.
                    </DialogDescription>
                </DialogHeader>
                {/* Keyed so a fresh fetch runs each time the dialog opens. */}
                {open && <PreviewBody key={catalogItemId} catalogItemId={catalogItemId} />}
            </DialogContent>
        </Dialog>
    );
}

function PreviewBody({ catalogItemId }: { catalogItemId: number }) {
    const [data, setData] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        // The dialog keys this component by card id, so it mounts fresh each open
        // — one fetch per mount, no reset of prior state needed.
        let alive = true;

        fetch(`/admin/cards/${catalogItemId}/comp-preview`, {
            headers: { Accept: 'application/json' },
        })
            .then(async (res) => {
                const body = (await res.json()) as Preview;

                if (!alive) {
                    return;
                }

                if (!res.ok || !body.ok) {
                    setError(body.message ?? 'Lookup failed.');

                    return;
                }

                setData(body);
            })
            .catch(() => alive && setError('Lookup failed.'));

        return () => {
            alive = false;
        };
    }, [catalogItemId]);

    if (error) {
        return <p className="py-8 text-center text-sm text-destructive">{error}</p>;
    }

    if (!data) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                Fetching live eBay sold listings…
            </p>
        );
    }

    return (
        <div className="flex min-h-0 flex-col gap-3">
            {/* The generated search term + a link to run it on eBay. */}
            <div className="space-y-1 rounded-md border bg-muted/40 p-3 text-sm">
                <div className="font-mono text-xs break-words text-foreground">
                    {data.query}
                </div>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <a
                        href={data.url}
                        target="_blank"
                        rel="noreferrer"
                        className="text-primary underline"
                    >
                        Open on eBay ↗
                    </a>
                    <span>
                        Anchor {formatMoney(data.anchor)} · {data.ingested}/
                        {data.candidates.length} would ingest
                    </span>
                </div>
            </div>

            <div className="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
                {data.candidates.length === 0 && (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        eBay returned no sold listings for this search.
                    </p>
                )}
                {data.candidates.map((c, i) => (
                    <CandidateRow key={c.url ?? i} candidate={c} />
                ))}
            </div>
        </div>
    );
}

function CandidateRow({ candidate: c }: { candidate: Candidate }) {
    const ingest = c.verdict === 'ingest';

    return (
        <div
            className={`flex gap-3 rounded-md border p-2 ${
                ingest
                    ? 'border-emerald-500/30 bg-emerald-500/5'
                    : 'border-border bg-muted/20 opacity-80'
            }`}
        >
            {c.image_url ? (
                <img
                    src={c.image_url}
                    alt=""
                    className="h-14 w-14 shrink-0 rounded object-contain"
                    loading="lazy"
                />
            ) : (
                <div className="h-14 w-14 shrink-0 rounded bg-muted" />
            )}

            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                    <p className="line-clamp-2 text-xs text-foreground">
                        {c.url ? (
                            <a
                                href={c.url}
                                target="_blank"
                                rel="noreferrer"
                                className="hover:underline"
                            >
                                {c.title}
                            </a>
                        ) : (
                            c.title
                        )}
                    </p>
                    <Badge variant={ingest ? 'default' : 'secondary'}>
                        {ingest ? 'Ingest' : 'Reject'}
                    </Badge>
                </div>
                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {formatMoney(c.price)}
                    </span>
                    {c.state && <span>· {c.state}</span>}
                    {c.sold_at && <span>· {relativeTime(c.sold_at)}</span>}
                    {c.seller && <span>· {c.seller}</span>}
                    {!ingest && c.reason && (
                        <span className="text-destructive">· {c.reason}</span>
                    )}
                </div>
            </div>
        </div>
    );
}
