import { router } from '@inertiajs/react';
import { Check, ListFilter, Search, Tag, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type RarityOption = { value: string; count: number };

export type FolderOption = { name: string; items_count: number };

export type ListFilters = {
    rarity: string[];
    sort: string;
    q: string | null;
    set: string | null;
    folder: string | null;
    for_sale: boolean;
};

/** Stands for "no set chosen" — Radix forbids an empty-string item value. */
const ANY = '__any__';

/** Must match ListControls::SORTS — the server rejects anything else. */
const SORTS: { value: string; label: string }[] = [
    { value: 'recent', label: 'Recently added' },
    { value: 'oldest', label: 'Oldest first' },
    { value: 'name', label: 'Name A–Z' },
    { value: 'set', label: 'Set, then number' },
    { value: 'value_desc', label: 'Most valuable' },
    { value: 'value_asc', label: 'Least valuable' },
];

/**
 * Sorts that need a cost basis or a quantity, so they only mean something on
 * the collection. They were the holdings table's own before sorting moved into
 * the URL, and are offered here so that move costs nothing.
 */
const PORTFOLIO_SORTS: { value: string; label: string }[] = [
    { value: 'gain_desc', label: 'P&L: best first' },
    { value: 'gain_asc', label: 'P&L: worst first' },
    { value: 'quantity', label: 'Quantity' },
];

type Props = {
    /** The page this bar drives: '/collection' or '/wishlist'. */
    url: string;
    filters: ListFilters;
    rarityOptions: RarityOption[];
    setOptions: string[];
    /** Props to re-request — everything else on the page is left alone. */
    only: string[];
    /** Query keys to carry through, e.g. the active collection or wishlist. */
    keep?: Record<string, string | null | undefined>;
    /** Offer the P&L and quantity sorts. Collection only. */
    portfolioSorts?: boolean;
    /** Offer a folder filter. Collection only; omit where there are none. */
    folders?: FolderOption[];
    /** Offer the "for sale" toggle. Collection only. */
    forSaleFilter?: boolean;
};

/**
 * The filter and sort bar shared by the collection and the wishlist.
 *
 * Every change is a navigation, not local state: the filters live in the URL,
 * so a filtered view can be refreshed, stepped back out of, and sent to
 * somebody else. The server decides what the list contains — this only
 * describes the request.
 */
export function ListControlsBar({
    url,
    filters,
    rarityOptions,
    setOptions,
    only,
    keep = {},
    portfolioSorts = false,
    folders,
    forSaleFilter = false,
}: Props) {
    const sorts = portfolioSorts ? [...SORTS, ...PORTFOLIO_SORTS] : SORTS;
    const selected = filters.rarity ?? [];
    const hasFilter =
        selected.length > 0 ||
        !!filters.q ||
        !!filters.set ||
        !!filters.folder ||
        filters.for_sale;
    const isSorted = filters.sort !== 'recent';

    // The search box is typed into locally and only then sent, so a keystroke
    // never waits on a round trip.
    const [q, setQ] = useState(filters.q ?? '');

    // What we last asked the server for, and what it last told us. Without
    // these, a response arriving mid-word overwrites what is being typed —
    // the bug that ate characters on the browse page. State, not a ref:
    // it is read during render, which a ref may not be.
    const [sentQ, setSentQ] = useState<string | null>(null);
    const [syncedQ, setSyncedQ] = useState(filters.q ?? '');

    if ((filters.q ?? '') !== syncedQ) {
        setSyncedQ(filters.q ?? '');

        // Only adopt the server's value when it is not simply the echo of what
        // we sent — otherwise a slow reply rewinds the box.
        if ((filters.q ?? '') !== sentQ) {
            setQ(filters.q ?? '');
        }
    }

    function go(
        next: Partial<ListFilters>,
        { replace = false }: { replace?: boolean } = {},
    ) {
        const merged = { ...filters, ...next };
        const query: Record<string, string | string[] | number> = {};

        // Only what is actually set goes in the URL. Defaults and empty values
        // would otherwise leave ?sort=recent&q= hanging around and make a
        // plain, unfiltered page look filtered.
        for (const [key, value] of Object.entries(keep)) {
            if (value) {
                query[key] = value;
            }
        }

        if (merged.rarity.length > 0) {
            query.rarity = merged.rarity;
        }

        if (merged.sort && merged.sort !== 'recent') {
            query.sort = merged.sort;
        }

        if (merged.q) {
            query.q = merged.q;
        }

        if (merged.set) {
            query.set = merged.set;
        }

        if (merged.folder) {
            query.folder = merged.folder;
        }

        if (merged.for_sale) {
            query.for_sale = 1;
        }

        router.get(url, query, {
            preserveState: true,
            preserveScroll: true,
            replace,
            only,
        });
    }

    // Search as you type, one request per pause. Each keystroke replaces the
    // history entry rather than stacking one, so Back does not walk the word
    // back letter by letter.
    useEffect(() => {
        const term = q.trim();

        if (term === (filters.q ?? '')) {
            return;
        }

        const timer = setTimeout(() => {
            setSentQ(term);
            go({ q: term || null }, { replace: true });
        }, 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    function toggleRarity(value: string) {
        go({
            rarity: selected.includes(value)
                ? selected.filter((r) => r !== value)
                : [...selected, value],
        });
    }

    function clearAll() {
        setQ('');
        setSentQ('');
        go({
            rarity: [],
            sort: 'recent',
            q: null,
            set: null,
            folder: null,
            for_sale: false,
        });
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="relative">
                <Search
                    className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden
                />
                <Input
                    value={q}
                    onChange={(event) => setQ(event.target.value)}
                    placeholder="Search name, number or set"
                    aria-label="Search this list"
                    className="h-8 w-60 pl-8"
                />
            </div>

            {rarityOptions.length > 0 && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" size="sm" className="gap-2">
                            <ListFilter className="size-4" aria-hidden />
                            Rarity
                            {selected.length > 0 && (
                                <Badge
                                    variant="secondary"
                                    className="ml-0.5 px-1.5"
                                >
                                    {selected.length}
                                </Badge>
                            )}
                        </Button>
                    </DropdownMenuTrigger>
                    {/* Scrolls, because a large collection can hold 26 rarities. */}
                    <DropdownMenuContent
                        align="start"
                        className="max-h-80 w-60 overflow-y-auto"
                    >
                        <DropdownMenuLabel>Filter by rarity</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {rarityOptions.map((option) => (
                            <DropdownMenuCheckboxItem
                                key={option.value}
                                checked={selected.includes(option.value)}
                                // Radix closes the menu on select; ticking two
                                // rarities in a row should not need reopening it.
                                onSelect={(event) => event.preventDefault()}
                                onCheckedChange={() =>
                                    toggleRarity(option.value)
                                }
                            >
                                <span className="flex-1 truncate">
                                    {option.value}
                                </span>
                                <span className="ml-2 text-muted-foreground tabular-nums">
                                    {option.count}
                                </span>
                            </DropdownMenuCheckboxItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}

            {setOptions.length > 1 && (
                <Select
                    value={filters.set ?? ANY}
                    onValueChange={(value) =>
                        go({ set: value === ANY ? null : value })
                    }
                >
                    <SelectTrigger size="sm" className="w-48" aria-label="Set">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent className="max-h-80">
                        <SelectItem value={ANY}>All sets</SelectItem>
                        {setOptions.map((name) => (
                            <SelectItem key={name} value={name}>
                                {name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}

            {folders && folders.length > 0 && (
                <Select
                    value={filters.folder ?? ANY}
                    onValueChange={(value) =>
                        go({ folder: value === ANY ? null : value })
                    }
                >
                    <SelectTrigger
                        size="sm"
                        className="w-44"
                        aria-label="Folder"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent className="max-h-80">
                        <SelectItem value={ANY}>All folders</SelectItem>
                        {folders.map((folder) => (
                            <SelectItem key={folder.name} value={folder.name}>
                                {folder.name} ({folder.items_count})
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}

            {forSaleFilter && (
                <Button
                    type="button"
                    size="sm"
                    variant={filters.for_sale ? 'default' : 'outline'}
                    onClick={() => go({ for_sale: !filters.for_sale })}
                >
                    <Tag className="size-4" aria-hidden />
                    For sale
                </Button>
            )}

            <Select value={filters.sort} onValueChange={(sort) => go({ sort })}>
                <SelectTrigger
                    size="sm"
                    className="w-[11.5rem]"
                    aria-label="Sort"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {sorts.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {/* The ticked rarities, each removable on its own — so what is being
                filtered stays visible without opening the menu. */}
            {selected.map((rarity) => (
                <Button
                    key={rarity}
                    variant="secondary"
                    size="sm"
                    className="gap-1"
                    onClick={() => toggleRarity(rarity)}
                >
                    <Check className="size-3.5" aria-hidden />
                    {rarity}
                    <X className="size-3.5 opacity-60" aria-hidden />
                    <span className="sr-only">Remove {rarity} filter</span>
                </Button>
            ))}

            {(hasFilter || isSorted) && (
                <Button variant="ghost" size="sm" onClick={clearAll}>
                    Clear
                </Button>
            )}
        </div>
    );
}
