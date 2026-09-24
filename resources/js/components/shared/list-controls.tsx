import { router } from '@inertiajs/react';
import { Check, ListFilter, X } from 'lucide-react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type RarityOption = { value: string; count: number };

export type ListFilters = {
    rarity: string[];
    sort: string;
};

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
    /** Props to re-request — everything else on the page is left alone. */
    only: string[];
    /** Query keys to carry through, e.g. the active collection or wishlist. */
    keep?: Record<string, string | null | undefined>;
    /** Offer the P&L and quantity sorts. Collection only. */
    portfolioSorts?: boolean;
};

/**
 * The filter and sort bar shared by the collection and the wishlist.
 *
 * Every change is a navigation, not local state: the filter lives in the URL,
 * so a filtered view can be refreshed, stepped back out of, and sent to
 * somebody else. The server is the only thing that decides what the list
 * contains — this just describes the request.
 */
export function ListControlsBar({
    url,
    filters,
    rarityOptions,
    only,
    keep = {},
    portfolioSorts = false,
}: Props) {
    const sorts = portfolioSorts ? [...SORTS, ...PORTFOLIO_SORTS] : SORTS;
    const selected = filters.rarity ?? [];
    const hasFilter = selected.length > 0;
    const isSorted = filters.sort !== 'recent';

    function go(next: Partial<ListFilters>) {
        const merged = { ...filters, ...next };

        const query: Record<string, string | string[]> = {};

        // Only what is actually set goes in the URL. A default sort or an empty
        // rarity list would otherwise leave ?sort=recent&rarity= hanging around
        // and make a plain, unfiltered page look filtered.
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

        router.get(url, query, {
            preserveState: true,
            preserveScroll: true,
            only,
        });
    }

    function toggleRarity(value: string) {
        go({
            rarity: selected.includes(value)
                ? selected.filter((r) => r !== value)
                : [...selected, value],
        });
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {rarityOptions.length > 0 && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" size="sm" className="gap-2">
                            <ListFilter className="size-4" aria-hidden />
                            Rarity
                            {hasFilter && (
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
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => go({ rarity: [], sort: 'recent' })}
                >
                    Clear
                </Button>
            )}
        </div>
    );
}
