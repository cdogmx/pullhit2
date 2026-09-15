import { Head, Link } from '@inertiajs/react';
import { ImageOff, MessageCircle, Pencil, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Row = {
    id: number;
    title: string;
    price_cents: number;
    currency: string;
    photo: string | null;
    category_label: string;
    status: string;
    status_label: string;
    editable: boolean;
    threads: number;
    deals: number;
    expires_at: string | null;
    url: string;
    edit_url: string;
};

type Props = {
    listings: Row[];
    counts: { active: number; draft: number; pending: number; sold: number };
};

/** Colour carries the state so the list reads at a glance, not by reading. */
const TONE: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    active: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    pending: 'bg-amber-500/10 text-amber-700 dark:text-amber-500',
    sold: 'bg-sky-500/10 text-sky-700 dark:text-sky-400',
};

export default function MarketplaceMine({ listings, counts }: Props) {
    return (
        <>
            <Head title="Selling" />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-5 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Selling
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {counts.active} active · {counts.draft} draft ·{' '}
                            {counts.pending} in progress · {counts.sold} sold
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/marketplace/new">
                            <Plus className="mr-1 size-4" />
                            List a card
                        </Link>
                    </Button>
                </div>

                {listings.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-border py-16 text-center">
                        <p className="text-muted-foreground">
                            You have not listed anything yet.
                        </p>
                        <Button asChild variant="outline" className="mt-3">
                            <Link href="/marketplace/new">List a card</Link>
                        </Button>
                    </div>
                ) : (
                    <ul className="divide-y divide-border rounded-xl border border-border">
                        {listings.map((l) => (
                            <li
                                key={l.id}
                                className="flex items-center gap-3 p-3"
                            >
                                <div className="size-14 shrink-0 overflow-hidden rounded-md bg-muted">
                                    {l.photo ? (
                                        <img
                                            src={l.photo}
                                            alt=""
                                            className="size-full object-cover"
                                        />
                                    ) : (
                                        <div className="flex size-full items-center justify-center text-muted-foreground">
                                            <ImageOff className="size-4" />
                                        </div>
                                    )}
                                </div>

                                <div className="min-w-0 flex-1">
                                    <Link
                                        href={l.url}
                                        className="truncate font-medium hover:underline"
                                    >
                                        {l.title}
                                    </Link>
                                    <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <span className="tabular-nums">
                                            {formatMoney(
                                                l.price_cents,
                                                l.currency,
                                            )}
                                        </span>
                                        <span>· {l.category_label}</span>
                                        {l.threads > 0 && (
                                            <span className="flex items-center gap-1">
                                                <MessageCircle className="size-3" />
                                                {l.threads}
                                            </span>
                                        )}
                                    </p>
                                </div>

                                <Badge
                                    variant="outline"
                                    className={cn(
                                        'shrink-0 border-transparent',
                                        TONE[l.status] ?? '',
                                    )}
                                >
                                    {l.status_label}
                                </Badge>

                                {l.editable && (
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="ghost"
                                        className="shrink-0"
                                    >
                                        <Link
                                            href={l.edit_url}
                                            aria-label={`Edit ${l.title}`}
                                        >
                                            <Pencil className="size-4" />
                                        </Link>
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {counts.draft > 0 && (
                    <p className="text-xs text-muted-foreground">
                        A draft is only visible here — nobody can find it in the
                        marketplace until you publish it.
                    </p>
                )}
            </div>
        </>
    );
}
