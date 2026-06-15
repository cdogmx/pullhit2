import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CardPickTile } from '@/components/scan/card-pick-tile';
import { Input } from '@/components/ui/input';
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
                <div className="flex max-h-80 flex-wrap gap-2 overflow-y-auto rounded-md border border-border p-2">
                    {results.map((card) => (
                        <CardPickTile
                            key={card.id}
                            card={card}
                            onSelect={() => onSelect(card)}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
