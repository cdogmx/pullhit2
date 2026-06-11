import { ChevronDown, ShoppingCart } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatMoney } from '@/lib/format';
import type { CardListings, EbayOption } from '@/types';

const REL = 'nofollow sponsored noopener';

/** Live eBay "buy it now" listings + an affiliate shop button (lazy-loaded). */
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

    const primary = data.ebay_options[0];
    const groups = data.ebay_options.reduce<Record<string, EbayOption[]>>(
        (acc, o) => {
            (acc[o.group] ??= []).push(o);

            return acc;
        },
        {},
    );

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

            {/* Split button: Near Mint by default, dropdown for other states. */}
            {primary && (
                <div className="flex">
                    <Button asChild variant="outline" size="sm" className="rounded-r-none">
                        <a href={primary.url} target="_blank" rel={REL}>
                            <ShoppingCart className="size-4" />
                            Shop on eBay · {primary.label}
                        </a>
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="outline"
                                size="sm"
                                className="rounded-l-none border-l-0 px-2"
                                aria-label="Choose condition or grade"
                            >
                                <ChevronDown className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="max-h-80 overflow-y-auto">
                            {Object.entries(groups).map(([group, options], gi) => (
                                <div key={group}>
                                    {gi > 0 && <DropdownMenuSeparator />}
                                    <DropdownMenuLabel className="text-xs text-muted-foreground">
                                        {group}
                                    </DropdownMenuLabel>
                                    {options.map((o) => (
                                        <DropdownMenuItem key={o.label} asChild>
                                            <a href={o.url} target="_blank" rel={REL}>
                                                {o.label}
                                            </a>
                                        </DropdownMenuItem>
                                    ))}
                                </div>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            )}

            <p className="mt-3 text-[11px] text-muted-foreground">
                As an eBay Partner, we may be compensated for qualifying purchases.
            </p>
        </div>
    );
}
