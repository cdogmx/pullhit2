import { Head } from '@inertiajs/react';
import { formatMoney } from '@/lib/format';

type Holding = {
    catalog_item_id: number | null;
    name: string | null;
    number: string | null;
    image_url: string | null;
    set: string | null;
    state_label: string;
    quantity: number;
    unit_value: number | null;
    market_value: number | null;
    currency: string;
};

type Props = {
    owner: { name: string; username: string | null };
    summary: {
        total_value: number;
        item_count: number;
        card_count: number;
        currency: string;
    };
    holdings: Holding[];
};

/**
 * Public, read-only view of a user's collection. Shows cards + market values,
 * but never cost basis or P&L (those stay private). Chrome from AppShell.
 */
export default function PublicCollection({ owner, summary, holdings }: Props) {
    return (
        <>
            <Head title={`${owner.name}'s collection`} />

            <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        {owner.name}&rsquo;s collection
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {summary.card_count.toLocaleString()} cards ·{' '}
                        {summary.item_count.toLocaleString()} holdings ·{' '}
                        <span className="font-medium text-foreground">
                            {formatMoney(summary.total_value, summary.currency)}
                        </span>{' '}
                        total value
                    </p>
                </div>

                {holdings.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border py-20 text-center text-sm text-muted-foreground">
                        This collection is empty.
                    </div>
                ) : (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {holdings.map((h, i) => (
                            <a
                                key={i}
                                href={`/catalog/${h.catalog_item_id}`}
                                className="group overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-ring"
                            >
                                <div className="aspect-[3/4] overflow-hidden bg-muted">
                                    {h.image_url ? (
                                        <img
                                            src={h.image_url}
                                            alt={h.name ?? ''}
                                            loading="lazy"
                                            className="size-full object-contain transition-transform group-hover:scale-105"
                                        />
                                    ) : (
                                        <div className="flex size-full items-center justify-center text-xs text-muted-foreground">
                                            No image
                                        </div>
                                    )}
                                </div>
                                <div className="space-y-1 p-3">
                                    <p
                                        className="truncate text-sm font-medium"
                                        title={h.name ?? ''}
                                    >
                                        {h.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {h.set}
                                        {h.number ? ` · ${h.number}` : ''}
                                    </p>
                                    <div className="flex items-center justify-between gap-2 text-xs">
                                        <span className="truncate text-muted-foreground">
                                            {h.state_label}
                                            {h.quantity > 1
                                                ? ` · ×${h.quantity}`
                                                : ''}
                                        </span>
                                        {h.market_value != null && (
                                            <span className="shrink-0 font-semibold">
                                                {formatMoney(
                                                    h.market_value,
                                                    h.currency,
                                                )}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </a>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
