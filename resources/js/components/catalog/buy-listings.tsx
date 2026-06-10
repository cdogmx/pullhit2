import { ExternalLink, ShoppingCart } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/format';
import type { CardListings } from '@/types';

const REL = 'nofollow sponsored noopener';

/** Live eBay "buy it now" listings + affiliate shop links (lazy-loaded). */
export function BuyListings({ itemId }: { itemId: number }) {
    const [data, setData] = useState<CardListings | null>(null);

    useEffect(() => {
        let active = true;
        fetch(`/api/v1/catalog/${itemId}/listings`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((d: CardListings) => {
                if (active) {
                    setData(d);
                }
            })
            .catch(() => {});

        return () => {
            active = false;
        };
    }, [itemId]);

    if (!data) {
        return null;
    }

    return (
        <div className="rounded-xl border border-border bg-card p-5">
            <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Buy this card
            </p>

            {data.listings.length > 0 && (
                <ul className="mb-3 divide-y divide-border">
                    {data.listings.map((l, i) => (
                        <li key={i}>
                            <a
                                href={l.url}
                                target="_blank"
                                rel={REL}
                                className="flex items-center gap-3 py-2 transition-opacity hover:opacity-80"
                            >
                                {l.image && (
                                    <img
                                        src={l.image}
                                        alt=""
                                        className="size-10 rounded object-contain"
                                        loading="lazy"
                                    />
                                )}
                                <span className="min-w-0 flex-1 truncate text-sm">
                                    {l.title}
                                </span>
                                <span className="text-sm font-semibold whitespace-nowrap">
                                    {formatMoney(l.price_cents)}
                                </span>
                            </a>
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex flex-wrap gap-2">
                <Button asChild variant="outline" size="sm">
                    <a href={data.ebay_url} target="_blank" rel={REL}>
                        <ShoppingCart className="size-4" />
                        Shop on eBay
                        <ExternalLink className="size-3" />
                    </a>
                </Button>
                <Button asChild variant="outline" size="sm">
                    <a href={data.tcgplayer_url} target="_blank" rel={REL}>
                        Buy on TCGplayer
                        <ExternalLink className="size-3" />
                    </a>
                </Button>
            </div>

            <p className="mt-3 text-[11px] text-muted-foreground">
                As an eBay Partner, we may be compensated for qualifying purchases.
            </p>
        </div>
    );
}
