import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { CatalogSearchSelect } from '@/components/scan/catalog-search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { AdminPagination, CatalogItem } from '@/types';

type Applied = {
    id: number;
    card: string;
    card_id: number | null;
    card_image: string | null;
    grade: string | null;
    price: number | null;
    title: string | null;
    image: string | null;
    url: string | null;
    search: string | null;
    observed_at: string | null;
};

type Miss = {
    id: number;
    title: string;
    image: string | null;
    url: string | null;
    reason: string;
    number: string | null;
    price: number | null;
    best: string | null;
    best_id: number | null;
    best_image: string | null;
    score: number | null;
    created_at: string | null;
};

type Props = {
    misses: Miss[];
    pagination: AdminPagination;
    counts: Record<string, number>;
    reason: string;
    applied: Applied[];
    appliedTotal: number;
};

const REASONS = [
    'no_number',
    'unmatched',
    'ambiguous',
    'low_score',
    'classify_rejected',
];

const REASON_STYLE: Record<string, string> = {
    no_number: 'text-muted-foreground',
    unmatched: 'text-muted-foreground',
    ambiguous: 'text-amber-600 dark:text-amber-400',
    low_score: 'text-amber-600 dark:text-amber-400',
    classify_rejected: 'text-red-600 dark:text-red-400',
};

const money = (cents: number | null) =>
    cents == null ? '—' : '$' + (cents / 100).toLocaleString(undefined, { maximumFractionDigits: 0 });

/** A labelled image panel (or a "no image" placeholder) for the compare view. */
function ImagePanel({
    src,
    label,
    href,
}: {
    src: string | null;
    label: string;
    href?: string | null;
}) {
    const frame = (
        <div className="flex aspect-square w-full items-center justify-center overflow-hidden rounded-md border border-border bg-muted/40">
            {src ? (
                <img
                    src={src}
                    alt={label}
                    loading="lazy"
                    className="size-full object-contain"
                />
            ) : (
                <span className="px-2 text-center text-[10px] text-muted-foreground">
                    No image
                </span>
            )}
        </div>
    );

    return (
        <div className="flex-1 space-y-1">
            <div className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                {label}
            </div>
            {href ? (
                <a href={href} target="_blank" rel="noopener noreferrer">
                    {frame}
                </a>
            ) : (
                frame
            )}
        </div>
    );
}

export default function AdminEbaySweep({
    misses,
    pagination,
    counts,
    reason,
    applied,
    appliedTotal,
}: Props) {
    const totalMisses = Object.values(counts).reduce((a, b) => a + b, 0);
    const [reassign, setReassign] = useState<Applied | null>(null);

    const reject = (a: Applied) => {
        if (!confirm('Reject this sale and stop it re-applying in future sweeps?')) {
            return;
        }

        router.post(
            `/admin/ebay-sweep/applied/${a.id}/reject`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Rejected and suppressed.'),
            },
        );
    };

    const pickCorrect = (card: CatalogItem) => {
        if (!reassign) {
            return;
        }

        router.post(
            `/admin/ebay-sweep/applied/${reassign.id}/reassign`,
            { catalog_item_id: card.id },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Reassigned to the correct card.');
                    setReassign(null);
                },
            },
        );
    };

    const [assignMiss, setAssignMiss] = useState<Miss | null>(null);

    const postAssign = (missId: number, cardId: number, done?: () => void) =>
        router.post(
            `/admin/ebay-sweep/misses/${missId}/assign`,
            { catalog_item_id: cardId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Assigned to the card.');
                    done?.();
                },
            },
        );

    const assignToBest = (m: Miss) => {
        if (m.best_id) {
            postAssign(m.id, m.best_id);
        }
    };

    const pickForMiss = (card: CatalogItem) => {
        if (assignMiss) {
            postAssign(assignMiss.id, card.id, () => setAssignMiss(null));
        }
    };

    const rejectMiss = (m: Miss) => {
        if (!confirm('Reject this listing and stop future sweeps re-logging it?')) {
            return;
        }

        router.post(
            `/admin/ebay-sweep/misses/${m.id}/reject`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Rejected and suppressed.'),
            },
        );
    };

    return (
        <>
            <Head title="Admin · eBay sweep" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                {/* Reason summary */}
                <div className="flex flex-wrap gap-2">
                    <Badge variant="secondary" className="text-xs">
                        {appliedTotal.toLocaleString()} applied
                    </Badge>
                    <Badge variant="secondary" className="text-xs">
                        {totalMisses.toLocaleString()} misses
                    </Badge>
                    {REASONS.map((r) => (
                        <Badge
                            key={r}
                            variant="outline"
                            className={cn('text-xs', REASON_STYLE[r])}
                        >
                            {r.replace('_', ' ')}: {counts[r] ?? 0}
                        </Badge>
                    ))}
                </div>

                {/* Recently applied matches */}
                <div>
                    <h2 className="mb-2 text-sm font-semibold">
                        Recently applied ({applied.length})
                    </h2>
                    <div className="overflow-hidden rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <tbody>
                                {applied.length === 0 && (
                                    <tr>
                                        <td className="px-3 py-6 text-center text-muted-foreground">
                                            No sweep sales applied yet.
                                        </td>
                                    </tr>
                                )}
                                {applied.map((a) => (
                                    <tr
                                        key={a.id}
                                        className="border-b border-border/60 last:border-0"
                                    >
                                        <td className="px-3 py-2">
                                            {a.url ? (
                                                <a
                                                    href={a.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="block truncate text-xs text-muted-foreground hover:text-foreground hover:underline"
                                                >
                                                    {a.title}
                                                </a>
                                            ) : (
                                                <div className="truncate text-xs text-muted-foreground">
                                                    {a.title}
                                                </div>
                                            )}
                                            <div className="font-medium">
                                                {a.card_id ? (
                                                    <Link
                                                        href={`/admin/cards/${a.card_id}`}
                                                        className="hover:underline"
                                                    >
                                                        {a.card}
                                                    </Link>
                                                ) : (
                                                    a.card
                                                )}
                                                {a.grade && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="ml-2 text-[10px]"
                                                    >
                                                        {a.grade}
                                                    </Badge>
                                                )}
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums">
                                            {money(a.price)}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => setReassign(a)}
                                                >
                                                    Reassign
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-red-600 hover:text-red-600"
                                                    onClick={() => reject(a)}
                                                >
                                                    Reject
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Misses */}
                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-sm font-semibold">
                            Unmatched ({pagination.total.toLocaleString()})
                        </h2>
                        <Select
                            value={reason}
                            onValueChange={(v) =>
                                router.get(
                                    '/admin/ebay-sweep',
                                    { reason: v },
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All reasons</SelectItem>
                                {REASONS.map((r) => (
                                    <SelectItem key={r} value={r}>
                                        {r.replace('_', ' ')}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="overflow-hidden rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <tbody>
                                {misses.length === 0 && (
                                    <tr>
                                        <td className="px-3 py-6 text-center text-muted-foreground">
                                            Nothing here.
                                        </td>
                                    </tr>
                                )}
                                {misses.map((m) => (
                                    <tr
                                        key={m.id}
                                        className="border-b border-border/60 last:border-0"
                                    >
                                        <td className="px-3 py-2">
                                            {m.url ? (
                                                <a
                                                    href={m.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="block truncate hover:underline"
                                                >
                                                    {m.title}
                                                </a>
                                            ) : (
                                                <div className="truncate">
                                                    {m.title}
                                                </div>
                                            )}
                                            <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                                <span
                                                    className={cn(
                                                        'font-medium capitalize',
                                                        REASON_STYLE[m.reason],
                                                    )}
                                                >
                                                    {m.reason.replace('_', ' ')}
                                                </span>
                                                {m.number && (
                                                    <span>#{m.number}</span>
                                                )}
                                                {m.best && (
                                                    <span>
                                                        ≈{' '}
                                                        {m.best_id ? (
                                                            <Link
                                                                href={`/admin/cards/${m.best_id}`}
                                                                className="hover:underline"
                                                            >
                                                                {m.best}
                                                            </Link>
                                                        ) : (
                                                            m.best
                                                        )}
                                                        {m.score != null &&
                                                            ` (${m.score})`}
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums text-muted-foreground">
                                            {money(m.price)}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right">
                                            <div className="flex justify-end gap-1">
                                                {m.best_id && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            assignToBest(m)
                                                        }
                                                    >
                                                        Assign to best
                                                    </Button>
                                                )}
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setAssignMiss(m)
                                                    }
                                                >
                                                    Assign…
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-red-600 hover:text-red-600"
                                                    onClick={() => rejectMiss(m)}
                                                >
                                                    Reject
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {pagination.last_page > 1 && (
                        <div className="mt-3 flex items-center justify-between text-sm">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pagination.page <= 1}
                                onClick={() =>
                                    router.get(
                                        '/admin/ebay-sweep',
                                        { reason, page: pagination.page - 1 },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Previous
                            </Button>
                            <span className="text-muted-foreground">
                                Page {pagination.page} of {pagination.last_page}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pagination.page >= pagination.last_page}
                                onClick={() =>
                                    router.get(
                                        '/admin/ebay-sweep',
                                        { reason, page: pagination.page + 1 },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Next
                            </Button>
                        </div>
                    )}
                </div>
            </div>

            <Dialog
                open={!!reassign}
                onOpenChange={(open) => !open && setReassign(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Reassign sale to the correct card</DialogTitle>
                    </DialogHeader>
                    {reassign && (
                        <div className="space-y-3">
                            <div className="flex gap-3">
                                <ImagePanel
                                    src={reassign.image}
                                    label="eBay listing"
                                    href={reassign.url}
                                />
                                <ImagePanel
                                    src={reassign.card_image}
                                    label="Matched card"
                                    href={
                                        reassign.card_id
                                            ? `/admin/cards/${reassign.card_id}`
                                            : null
                                    }
                                />
                            </div>
                            <div className="rounded-md border border-border bg-muted/40 p-3 text-xs">
                                <div className="text-muted-foreground">
                                    Listing
                                </div>
                                <div className="mb-2">{reassign.title}</div>
                                <div className="text-muted-foreground">
                                    Matched to
                                </div>
                                <div>
                                    {reassign.card}
                                    {reassign.grade && ` · ${reassign.grade}`}
                                </div>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Search for the correct card — the sale moves there
                                and future sweeps of this listing follow.
                            </p>
                            <CatalogSearchSelect onSelect={pickCorrect} />
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={!!assignMiss}
                onOpenChange={(open) => !open && setAssignMiss(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Assign listing to a card</DialogTitle>
                    </DialogHeader>
                    {assignMiss && (
                        <div className="space-y-3">
                            <div className="flex gap-3">
                                <ImagePanel
                                    src={assignMiss.image}
                                    label="eBay listing"
                                    href={assignMiss.url}
                                />
                                <ImagePanel
                                    src={assignMiss.best_image}
                                    label={
                                        assignMiss.best_id
                                            ? `Best guess${assignMiss.score != null ? ` (${assignMiss.score})` : ''}`
                                            : 'Best guess'
                                    }
                                    href={
                                        assignMiss.best_id
                                            ? `/admin/cards/${assignMiss.best_id}`
                                            : null
                                    }
                                />
                            </div>
                            <div className="rounded-md border border-border bg-muted/40 p-3 text-xs">
                                <div className="text-muted-foreground">
                                    Listing
                                </div>
                                <div>{assignMiss.title}</div>
                            </div>
                            {assignMiss.best_id && (
                                <Button
                                    size="sm"
                                    className="w-full"
                                    onClick={() => {
                                        if (assignMiss.best_id) {
                                            postAssign(
                                                assignMiss.id,
                                                assignMiss.best_id,
                                                () => setAssignMiss(null),
                                            );
                                        }
                                    }}
                                >
                                    Assign to best guess — {assignMiss.best}
                                </Button>
                            )}
                            <p className="text-xs text-muted-foreground">
                                …or search for the correct card. It's ingested as a
                                real comp and future sweeps of this listing follow.
                            </p>
                            <CatalogSearchSelect onSelect={pickForMiss} />
                            <div className="border-t border-border pt-2">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="text-red-600 hover:text-red-600"
                                    onClick={() => {
                                        rejectMiss(assignMiss);
                                        setAssignMiss(null);
                                    }}
                                >
                                    Reject listing — not a card we track
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

AdminEbaySweep.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'eBay sweep', href: '/admin/ebay-sweep' },
    ],
};
