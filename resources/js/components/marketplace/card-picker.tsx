import { Link2, Loader2, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/format';

export type CardHit = {
    id: number;
    name: string;
    number: string | null;
    set: string | null;
    set_code: string | null;
    line: string | null;
    thumb: string | null;
    market_cents: number | null;
    url: string | null;
};

type Props = {
    /** Seeds the first search, so the title a seller already typed does the work. */
    seed: string;
    selected: CardHit | null;
    onSelect: (card: CardHit | null) => void;
};

/**
 * Point a listing at the catalogued card it is a copy of.
 *
 * Seeded from the title rather than made a separate chore: a seller who has
 * typed "Charizard ex 223 Obsidian Flames" has already said which card it is,
 * and asking them again is asking twice.
 */
export function CardPicker({ seed, selected, onSelect }: Props) {
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<CardHit[]>([]);
    const [loading, setLoading] = useState(false);
    const [touched, setTouched] = useState(false);

    // Until the seller edits the box themselves, it follows the title.
    const query = touched ? q : seed;

    // Derived, not stored: whether results should show follows from the query
    // and the selection, so there is no second copy of that fact to fall out of
    // step — and no setState in an effect body to trigger a cascading render.
    const searchable = !selected && query.trim().length >= 2;
    const visible = searchable ? hits : [];

    useEffect(() => {
        if (!searchable) {
            return;
        }

        const id = window.setTimeout(async () => {
            setLoading(true);

            try {
                const res = await fetch(
                    `/marketplace/card-search?q=${encodeURIComponent(query)}`,
                    { headers: { Accept: 'application/json' } },
                );
                const body = await res.json();

                setHits(body.cards ?? []);
            } catch {
                setHits([]);
            } finally {
                setLoading(false);
            }
        }, 300);

        return () => window.clearTimeout(id);
    }, [query, searchable]);

    if (selected) {
        return (
            <div className="flex items-center gap-3 rounded-lg border border-border p-3">
                {selected.thumb && (
                    <img
                        src={selected.thumb}
                        alt=""
                        className="size-12 rounded object-cover"
                    />
                )}
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                        {selected.name}
                        {selected.number && (
                            <span className="ml-1 text-muted-foreground">
                                #{selected.number}
                            </span>
                        )}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {selected.set}
                        {selected.market_cents != null && (
                            <>{` · market ${formatMoney(selected.market_cents)}`}</>
                        )}
                    </p>
                </div>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => onSelect(null)}
                    aria-label="Unlink this card"
                >
                    <X className="size-4" />
                </Button>
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                    value={query}
                    onChange={(e) => {
                        setTouched(true);
                        setQ(e.target.value);
                    }}
                    placeholder="Search the catalogue — name, number or set"
                    className="pl-8"
                />
                {loading && (
                    <Loader2 className="absolute top-1/2 right-2.5 size-3.5 -translate-y-1/2 animate-spin text-muted-foreground" />
                )}
            </div>

            {visible.length > 0 && (
                <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                    {visible.map((c) => (
                        <li key={c.id}>
                            <button
                                type="button"
                                onClick={() => onSelect(c)}
                                className="flex w-full items-center gap-3 p-2 text-left hover:bg-muted/50"
                            >
                                {c.thumb ? (
                                    <img
                                        src={c.thumb}
                                        alt=""
                                        className="size-10 rounded object-cover"
                                    />
                                ) : (
                                    <div className="size-10 rounded bg-muted" />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm">
                                        {c.name}
                                        {c.number && (
                                            <span className="ml-1 text-muted-foreground">
                                                #{c.number}
                                            </span>
                                        )}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {c.set}
                                        {c.set_code ? ` (${c.set_code})` : ''}
                                    </p>
                                </div>
                                {c.market_cents != null && (
                                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                        {formatMoney(c.market_cents)}
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <p className="flex items-center gap-1 text-xs text-muted-foreground">
                <Link2 className="size-3" />
                Optional, but a linked card shows buyers your price beside the
                market, and puts the listing on that card&rsquo;s page.
            </p>
        </div>
    );
}
