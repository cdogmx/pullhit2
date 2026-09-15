import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Handshake, ShieldCheck, Star } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Message = {
    id: number;
    body: string;
    sender: string | null;
    sender_id: number;
    sent_at: string | null;
};

type Deal = {
    id: number;
    type: string;
    status: string;
    status_label: string;
    price_cents: number;
    awaiting_me: boolean;
    i_confirmed: boolean;
    they_confirmed: boolean;
    can_rate: boolean;
    cancellable: boolean;
} | null;

type Props = {
    thread: {
        id: number;
        selling: boolean;
        other: {
            username: string | null;
            direct_deals: number;
            protected_deals: number;
            rating: number | null;
        } | null;
        listing: {
            id: number | null;
            title: string | null;
            photo: string | null;
            price_cents: number | null;
            status: string | null;
            url: string | null;
        };
    };
    messages: Message[];
    deal: Deal;
    auth: { user: { id: number } };
};

export default function MarketplaceThread({
    thread,
    messages: initial,
    deal: initialDeal,
    auth,
}: Props) {
    const [messages, setMessages] = useState(initial);
    const [deal, setDeal] = useState(initialDeal);
    const [logging, setLogging] = useState(false);
    const [rating, setRating] = useState(0);
    const bottom = useRef<HTMLDivElement>(null);

    const { data, setData, post, processing, reset } = useForm({ body: '' });

    // Polling, not websockets: a marketplace thread is two people typing
    // occasionally, and an open socket per viewer is a lot of machinery to
    // carry for that. Worth revisiting if threads ever get busy.
    useEffect(() => {
        const id = window.setInterval(async () => {
            const last = messages.length ? messages[messages.length - 1].id : 0;

            try {
                const res = await fetch(
                    `/messages/${thread.id}/poll?after=${last}`,
                    { headers: { Accept: 'application/json' } },
                );
                const body = await res.json();

                if (body.messages?.length) {
                    setMessages((m) => [...m, ...body.messages]);
                }

                setDeal(body.deal ?? null);
            } catch {
                // A dropped poll is not worth telling anyone about; the next
                // one is four seconds away.
            }
        }, 4000);

        return () => window.clearInterval(id);
    }, [thread.id, messages]);

    useEffect(() => {
        bottom.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.length]);

    const send = () => {
        if (!data.body.trim()) {
            return;
        }

        post(`/messages/${thread.id}`, {
            preserveScroll: true,
            onSuccess: () => reset('body'),
        });
    };

    const act = (action: string) =>
        router.post(
            `/deals/${deal?.id}/act`,
            { action },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={thread.listing.title ?? 'Conversation'} />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                {/* What this is about */}
                <div className="flex items-center gap-3 rounded-xl border border-border p-3">
                    {thread.listing.photo && (
                        <img
                            src={thread.listing.photo}
                            alt=""
                            className="size-14 rounded-md object-cover"
                        />
                    )}
                    <div className="min-w-0 flex-1">
                        {thread.listing.url ? (
                            <Link
                                href={thread.listing.url}
                                className="truncate font-medium hover:underline"
                            >
                                {thread.listing.title}
                            </Link>
                        ) : (
                            <p className="truncate font-medium">
                                {thread.listing.title ?? 'Listing removed'}
                            </p>
                        )}
                        <p className="text-sm text-muted-foreground tabular-nums">
                            {formatMoney(thread.listing.price_cents)}
                            {thread.listing.status &&
                                thread.listing.status !== 'active' && (
                                    <span className="ml-2">
                                        · {thread.listing.status}
                                    </span>
                                )}
                        </p>
                    </div>
                    {thread.other && (
                        <div className="shrink-0 text-right text-xs text-muted-foreground">
                            <p className="font-medium text-foreground">
                                @{thread.other.username}
                            </p>
                            <p className="flex items-center justify-end gap-1">
                                <ShieldCheck className="size-3" />
                                {thread.other.protected_deals} protected
                            </p>
                            <p>{thread.other.direct_deals} direct</p>
                            {thread.other.rating !== null && (
                                <p>★ {thread.other.rating.toFixed(2)}</p>
                            )}
                        </div>
                    )}
                </div>

                {/* The deal, when there is one */}
                {deal ? (
                    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-border p-3">
                        <Handshake className="size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium">
                                {formatMoney(deal.price_cents)} ·{' '}
                                {deal.status_label}
                            </p>
                            {deal.status === 'accepted' && (
                                <p className="text-xs text-muted-foreground">
                                    {deal.i_confirmed
                                        ? 'You have confirmed. Waiting on them.'
                                        : 'Confirm once the card and the money have changed hands.'}
                                    {deal.they_confirmed &&
                                        !deal.i_confirmed &&
                                        ' They have already confirmed.'}
                                </p>
                            )}
                        </div>

                        <div className="flex shrink-0 flex-wrap gap-2">
                            {deal.status === 'proposed' && deal.awaiting_me && (
                                <Button size="sm" onClick={() => act('accept')}>
                                    Agree
                                </Button>
                            )}
                            {deal.status === 'accepted' &&
                                !deal.i_confirmed && (
                                    <Button
                                        size="sm"
                                        onClick={() => act('confirm')}
                                    >
                                        <Check className="mr-1 size-3" />
                                        Confirm completed
                                    </Button>
                                )}
                            {deal.cancellable && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => act('cancel')}
                                >
                                    Cancel
                                </Button>
                            )}
                        </div>

                        {deal.can_rate && (
                            <div className="flex w-full items-center gap-2 border-t border-border pt-3">
                                <span className="text-sm">How did it go?</span>
                                {[1, 2, 3, 4, 5].map((n) => (
                                    <button
                                        key={n}
                                        type="button"
                                        aria-label={`${n} star${n === 1 ? '' : 's'}`}
                                        onClick={() => {
                                            setRating(n);
                                            router.post(
                                                `/deals/${deal.id}/rate`,
                                                { rating: n },
                                                { preserveScroll: true },
                                            );
                                        }}
                                    >
                                        <Star
                                            className={cn(
                                                'size-5',
                                                n <= rating
                                                    ? 'fill-amber-400 text-amber-400'
                                                    : 'text-muted-foreground',
                                            )}
                                        />
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-dashed border-border p-3">
                        <p className="flex-1 text-sm text-muted-foreground">
                            Agreed a price? Log it so it counts toward both of
                            your reputations.
                        </p>
                        {logging ? (
                            <LogDealForm
                                threadId={thread.id}
                                suggested={thread.listing.price_cents ?? 0}
                                onCancel={() => setLogging(false)}
                            />
                        ) : (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setLogging(true)}
                            >
                                Log this deal
                            </Button>
                        )}
                    </div>
                )}

                {/* Messages */}
                <div className="flex min-h-64 flex-1 flex-col gap-2 overflow-y-auto rounded-xl border border-border p-3">
                    {messages.length === 0 && (
                        <p className="m-auto text-sm text-muted-foreground">
                            Say hello.
                        </p>
                    )}
                    {messages.map((m) => {
                        const mine = m.sender_id === auth.user.id;

                        return (
                            <div
                                key={m.id}
                                className={cn(
                                    'max-w-[80%] rounded-lg px-3 py-2 text-sm',
                                    mine
                                        ? 'ml-auto bg-primary text-primary-foreground'
                                        : 'bg-muted',
                                )}
                            >
                                <p className="whitespace-pre-line">{m.body}</p>
                            </div>
                        );
                    })}
                    <div ref={bottom} />
                </div>

                <div className="flex items-end gap-2">
                    <Textarea
                        rows={2}
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                send();
                            }
                        }}
                        placeholder="Write a message…"
                    />
                    <Button onClick={send} disabled={processing}>
                        Send
                    </Button>
                </div>

                <p className="rounded-lg border border-border bg-muted/40 p-3 text-xs text-muted-foreground">
                    CardFoo is a venue only and is not party to this
                    transaction. Never pay a stranger by Friends &amp; Family —
                    it has no buyer protection, and a seller asking for it is
                    the most common way people are scammed here.
                </p>
            </div>
        </>
    );
}

/** Log a price both sides then have to agree to. */
function LogDealForm({
    threadId,
    suggested,
    onCancel,
}: {
    threadId: number;
    suggested: number;
    onCancel: () => void;
}) {
    const [dollars, setDollars] = useState((suggested / 100).toFixed(2));

    return (
        <div className="flex items-center gap-2">
            <Input
                value={dollars}
                onChange={(e) => setDollars(e.target.value)}
                inputMode="decimal"
                className="h-9 w-28 tabular-nums"
            />
            <Button
                size="sm"
                onClick={() =>
                    router.post(
                        `/messages/${threadId}/deal`,
                        {
                            price_cents: Math.round(Number(dollars) * 100),
                        },
                        { preserveScroll: true },
                    )
                }
            >
                Log
            </Button>
            <Button size="sm" variant="ghost" onClick={onCancel}>
                Cancel
            </Button>
        </div>
    );
}
