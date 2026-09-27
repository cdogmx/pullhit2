import { Head, router } from '@inertiajs/react';
import { Check, GitMerge, Sparkles, Users, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type ProvisionalCard = {
    id: number;
    name: string;
    number: string | null;
    set: string | null;
    brand: string | null;
    new_brand: boolean;
    new_set: boolean;
    image_url: string | null;
    scans: number;
    read: Record<string, string | number | null> | null;
    found_by: string | null;
    found_at: string | null;
    owners: number;
};

type Props = {
    cards: { data: ProvisionalCard[]; links: { url: string | null; label: string; active: boolean }[] };
    total: number;
};

const READ_ORDER = [
    'name',
    'number',
    'set_name',
    'set_code',
    'product_line',
    'language',
    'variant',
    'edition',
    'confidence',
];

/**
 * What the scanner read, in a fixed order.
 *
 * Shown verbatim because it is the evidence: confirming a row means agreeing that
 * this read describes a real card, and the reviewer cannot judge that from the
 * tidied-up row alone.
 */
function ReadOut({ read }: { read: ProvisionalCard['read'] }) {
    if (!read) return null;

    const entries = READ_ORDER.filter((k) => read[k] != null && read[k] !== '');

    return (
        <dl className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            {entries.map((k) => (
                <div key={k} className="flex gap-1">
                    <dt className="text-muted-foreground/70">
                        {k.replace(/_/g, ' ')}
                    </dt>
                    <dd className="font-medium text-foreground">
                        {String(read[k])}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function Row({ card }: { card: ProvisionalCard }) {
    const [mergeInto, setMergeInto] = useState('');
    const [busy, setBusy] = useState(false);

    const act = (
        method: 'post' | 'delete',
        url: string,
        data: Record<string, string> = {},
    ) => {
        setBusy(true);
        router[method](url, data, {
            preserveScroll: true,
            onSuccess: () => toast.success('Done'),
            onError: (errors) =>
                toast.error(Object.values(errors)[0] ?? 'That did not work'),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Card>
            <CardContent className="flex flex-col gap-3 pt-6 sm:flex-row sm:items-start">
                <div className="flex w-full gap-3 sm:w-auto">
                    {card.image_url ? (
                        <img
                            src={card.image_url}
                            alt={card.name}
                            className="h-28 w-20 shrink-0 rounded object-contain"
                        />
                    ) : (
                        <div className="grid h-28 w-20 shrink-0 place-items-center rounded border border-dashed border-border text-xs text-muted-foreground">
                            no image
                        </div>
                    )}
                </div>

                <div className="flex min-w-0 flex-1 flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{card.name}</span>
                        {card.number && (
                            <span className="text-sm text-muted-foreground tabular-nums">
                                #{card.number}
                            </span>
                        )}
                        {/* Scans first: it is the reason this row is at the top. */}
                        <Badge variant="secondary" className="gap-1">
                            <Sparkles className="size-3" />
                            {card.scans} scan{card.scans === 1 ? '' : 's'}
                        </Badge>
                        {card.owners > 0 && (
                            <Badge variant="outline" className="gap-1">
                                <Users className="size-3" />
                                {card.owners} holding
                            </Badge>
                        )}
                    </div>

                    <p className="text-sm text-muted-foreground">
                        {card.brand ?? 'unknown brand'}
                        {card.new_brand && (
                            <Badge className="ml-1.5 text-[10px]">new brand</Badge>
                        )}
                        {' · '}
                        {card.set ?? 'unknown set'}
                        {card.new_set && (
                            <Badge className="ml-1.5 text-[10px]">new set</Badge>
                        )}
                    </p>

                    <ReadOut read={card.read} />

                    {card.found_by && (
                        <p className="text-xs text-muted-foreground">
                            found by {card.found_by}
                            {card.found_at
                                ? ` on ${new Date(card.found_at).toLocaleDateString()}`
                                : ''}
                        </p>
                    )}
                </div>

                <div className="flex w-full flex-col gap-2 sm:w-64">
                    <Button
                        size="sm"
                        disabled={busy}
                        onClick={() =>
                            act('post', `/admin/provisional-cards/${card.id}/confirm`)
                        }
                    >
                        <Check className="size-4" />
                        Confirm
                    </Button>

                    <div className="flex gap-1.5">
                        <Input
                            value={mergeInto}
                            onChange={(e) => setMergeInto(e.target.value)}
                            placeholder="Merge into card id"
                            inputMode="numeric"
                            className="h-9"
                            aria-label={`Card id to merge ${card.name} into`}
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={busy || mergeInto.trim() === ''}
                            onClick={() =>
                                act(
                                    'post',
                                    `/admin/provisional-cards/${card.id}/merge`,
                                    { into: mergeInto.trim() },
                                )
                            }
                        >
                            <GitMerge className="size-4" />
                        </Button>
                    </div>

                    <Button
                        size="sm"
                        variant="ghost"
                        disabled={busy}
                        onClick={() =>
                            act('delete', `/admin/provisional-cards/${card.id}`)
                        }
                        // Not disabled when held: the refusal explains itself, and
                        // a silently dead button teaches nothing.
                        title={
                            card.owners > 0
                                ? 'Held by somebody — merge it instead'
                                : 'Delete this row'
                        }
                    >
                        <X className="size-4" />
                        Reject
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export default function ProvisionalCards({ cards, total }: Props) {
    return (
        <>
            <Head title="Provisional cards" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <p className="text-xs text-muted-foreground">Admin</p>
                    <h1 className="text-2xl font-bold tracking-tight">
                        Cards added by scans
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                        {total} row{total === 1 ? '' : 's'} created by a scan that
                        matched nothing. Each is usable by whoever scanned it and
                        hidden from browse, search, pricing and the sitemap until
                        confirmed here. Ordered by how many scans reached it, which
                        is the best signal of what is worth adding properly.
                    </p>
                    <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                        Reject deletes the row, and only works when nobody holds
                        it — a card in somebody&rsquo;s collection has to be merged
                        into the right card instead, which moves their holding
                        across. Comps are never carried over: they were gathered
                        against a number that may have been misread.
                    </p>
                </div>

                {cards.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                            Nothing waiting. Scans are finding everything in the
                            catalog.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="flex flex-col gap-3">
                        {cards.data.map((card) => (
                            <Row key={card.id} card={card} />
                        ))}
                    </div>
                )}

                {cards.links.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {cards.links.map((link, i) => (
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
