import { Head, Link } from '@inertiajs/react';
import { ImageOff, MessageCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Thread = {
    id: number;
    listing: {
        title: string | null;
        photo: string | null;
        price_cents: number | null;
    };
    other: string | null;
    selling: boolean;
    messages: number;
    unread: boolean;
    last_message_at: string | null;
    url: string;
};

export default function MarketplaceInbox({ threads }: { threads: Thread[] }) {
    return (
        <>
            <Head title="Messages" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">
                        Messages
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Conversations about cards you are buying or selling.
                    </p>
                </div>

                {threads.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-border py-16 text-center">
                        <MessageCircle className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-2 text-muted-foreground">
                            No conversations yet.
                        </p>
                        <Link
                            href="/marketplace"
                            className="mt-2 inline-block text-sm underline underline-offset-2"
                        >
                            Browse the marketplace
                        </Link>
                    </div>
                ) : (
                    <ul className="divide-y divide-border rounded-xl border border-border">
                        {threads.map((t) => (
                            <li key={t.id}>
                                <Link
                                    href={t.url}
                                    className="flex items-center gap-3 p-3 hover:bg-muted/40"
                                >
                                    <div className="size-12 shrink-0 overflow-hidden rounded-md bg-muted">
                                        {t.listing.photo ? (
                                            <img
                                                src={t.listing.photo}
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
                                        <p
                                            className={cn(
                                                'truncate text-sm',
                                                t.unread
                                                    ? 'font-semibold'
                                                    : 'font-medium',
                                            )}
                                        >
                                            {t.listing.title ??
                                                'Listing removed'}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {t.selling ? 'Buyer' : 'Seller'}: @
                                            {t.other ?? 'unknown'}
                                            {' · '}
                                            {t.messages} message
                                            {t.messages === 1 ? '' : 's'}
                                        </p>
                                    </div>

                                    <div className="flex shrink-0 items-center gap-2">
                                        {t.listing.price_cents != null && (
                                            <span className="text-sm tabular-nums">
                                                {formatMoney(
                                                    t.listing.price_cents,
                                                )}
                                            </span>
                                        )}
                                        {t.unread && <Badge>New</Badge>}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
