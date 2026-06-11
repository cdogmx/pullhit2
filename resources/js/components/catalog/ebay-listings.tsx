import { formatMoney } from '@/lib/format';
import type { EbayListing } from '@/types';

const REL = 'nofollow sponsored noopener';

/** Compact list of live eBay "buy it now" listings (affiliate-linked). */
export function EbayListings({ listings }: { listings: EbayListing[] }) {
    if (listings.length === 0) {
        return null;
    }

    return (
        <ul className="divide-y divide-border">
            {listings.map((l, i) => (
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
    );
}
