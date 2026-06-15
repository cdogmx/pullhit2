import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { formatMoney, languageLabel } from '@/lib/format';
import type { CatalogItem } from '@/types';

/**
 * A debounced catalog search the user can fall back to when the scan didn't
 * surface the right card (or any card). Hits the JSON /scan/search endpoint and
 * lets them pick the correct item.
 */
export function CatalogSearchSelect({
    onSelect,
}: {
    onSelect: (card: CatalogItem) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<CatalogItem[]>([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const q = query.trim();

        if (q.length < 2) {
            setResults([]);
            setBusy(false);

            return;
        }

        setBusy(true);
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`/scan/search?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((r) => r.json())
                .then((d) => setResults(d.results ?? []))
                .catch(() => {
                    /* aborted or failed — leave prior results */
                })
                .finally(() => setBusy(false));
        }, 300);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [query]);

    return (
        <div className="space-y-2">
            <div className="relative">
                <Input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search by name, number, or set…"
                    autoComplete="off"
                />
                {busy && (
                    <Loader2 className="absolute right-2 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                )}
            </div>

            {query.trim().length >= 2 && !busy && results.length === 0 && (
                <p className="text-xs text-muted-foreground">No matches.</p>
            )}

            {results.length > 0 && (
                <ul className="max-h-64 divide-y divide-border overflow-auto rounded-md border border-border">
                    {results.map((card) => (
                        <li key={card.id}>
                            <button
                                type="button"
                                onClick={() => onSelect(card)}
                                className="flex w-full items-center gap-2 px-2 py-1.5 text-left hover:bg-accent/40"
                            >
                                {card.image_url && (
                                    <img
                                        src={card.image_url}
                                        alt=""
                                        className="h-10 w-auto shrink-0 rounded"
                                    />
                                )}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">
                                        {card.display_name ?? card.name}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {card.number}
                                        {card.set?.name
                                            ? ` · ${card.set.name}`
                                            : ''}
                                        {card.language
                                            ? ` · ${languageLabel(card.language)}`
                                            : ''}
                                        {card.market_value
                                            ? ` · ${formatMoney(card.market_value.median, card.market_value.currency)}`
                                            : ''}
                                    </span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
