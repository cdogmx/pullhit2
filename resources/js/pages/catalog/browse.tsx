import { Head, Link, router, usePage, WhenVisible } from '@inertiajs/react';
import {
    ArrowDownUp,
    LayoutGrid,
    Layers,
    List,
    Plus,
    Search,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PriceTag } from '@/components/catalog/price-tag';
import { AddToCollectionDialog } from '@/components/collection/add-to-collection-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { languageLabel } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    CatalogFilterOptions,
    CatalogFilters,
    CatalogItem,
    GradingCompanyOption,
} from '@/types';

type Pagination = {
    page: number;
    last_page: number;
    per_page: number;
    total: number;
    has_more: boolean;
};

type BrowseTile = {
    kind: 'brand' | 'series' | 'set' | 'subset';
    slug: string;
    /** Set a subset tile navigates to (a gallery child set); null = the main set. */
    set_slug?: string | null;
    name: string;
    code?: string | null;
    language?: string | null;
    released_at?: string | null;
    count: number;
    thumb: string | null;
};

type Props = {
    items: CatalogItem[];
    pagination: Pagination;
    options: CatalogFilterOptions;
    filters: CatalogFilters;
    gradingCompanies: GradingCompanyOption[];
    seo?: { title: string; heading: string } | null;
    /** Smart browse: brands → series → sets → subsets → cards. */
    mode: 'brands' | 'series' | 'sets' | 'subsets' | 'cards';
    tiles: BrowseTile[];
    tileLanguages: string[];
    /** Admin-authored description for the current brand/set. */
    blurb: string | null;
};

const SORTS = [
    { value: 'number', label: 'Number' },
    { value: 'name', label: 'Name' },
    { value: 'set', label: 'Set' },
    { value: 'newest', label: 'Newest' },
    { value: 'price', label: 'Price' },
    { value: 'change', label: '% Change' },
];

// Sorts that read best high-to-low by default (the toggle can still flip them).
const DESC_BY_DEFAULT: Record<string, 'asc' | 'desc'> = {
    price: 'desc',
    change: 'desc',
    newest: 'desc',
};

const ALL = '__all__';

/** The only subset value that appears as a filter is the parent's own "main" set. */
const subsetLabel = (key: string): string =>
    key === 'main' ? 'Main set' : key;

const humanize = (value: string): string =>
    value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

function buildQuery(filters: CatalogFilters): Record<string, string | number> {
    const out: Record<string, string | number> = {};

    if (filters.q) {
        out.q = filters.q;
    }

    for (const key of [
        'vertical',
        'product_line',
        'series',
        'set',
        'subset',
        'item_type',
        'language',
        'rarity',
        'variant',
    ] as const) {
        const value = filters[key];

        if (value) {
            out[key] = value;
        }
    }

    if (filters.sort && filters.sort !== 'number') {
        out.sort = filters.sort;
    }

    if (filters.direction && filters.direction !== 'asc') {
        out.direction = filters.direction;
    }

    if (filters.group) {
        out.group = 1;
    }

    if (filters.per_page && filters.per_page !== 24) {
        out.per_page = filters.per_page;
    }

    return out;
}

// Refinement chips shown in cards view. The brand→series→set→subset path is the
// breadcrumb's job, so those navigational keys are intentionally excluded here.
const ACTIVE_KEYS: (keyof CatalogFilters)[] = [
    'q',
    'item_type',
    'language',
    'rarity',
    'variant',
];

export default function Browse({
    items,
    pagination,
    options,
    filters,
    gradingCompanies,
    seo,
    mode,
    tiles,
    tileLanguages,
    blurb,
}: Props) {
    const { auth } = usePage().props;
    const canAdd = Boolean(auth.user);
    const [q, setQ] = useState(filters.q ?? '');
    const [view, setView] = useState<'grid' | 'list'>(filters.view ?? 'grid');
    const firstRender = useRef(true);

    // Debounced search.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timer = setTimeout(() => update({ q: q || null }), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    // A filter/sort/group change resets the infinite-scroll list back to page 1.
    function update(partial: Partial<CatalogFilters>) {
        const next = buildQuery({ ...filters, ...partial });
        router.get('/browse', next, {
            preserveState: true,
            replace: true,
            reset: ['items'],
            only: [
                'items',
                'pagination',
                'options',
                'filters',
                'seo',
                'mode',
                'blurb',
                'tiles',
                'tileLanguages',
            ],
        });
    }

    function reset() {
        setQ('');
        router.get(
            '/browse',
            {},
            {
                reset: ['items'],
                only: [
                'items',
                'pagination',
                'options',
                'filters',
                'seo',
                'mode',
                'blurb',
                'tiles',
                'tileLanguages',
            ],
            },
        );
    }

    const activeCount = ACTIVE_KEYS.filter((k) => filters[k]).length;

    // Thread the current browse query onto detail links so "Back to browse"
    // (and printing hops) can return here with the same search/filters/page.
    const returnTo =
        typeof window !== 'undefined' && window.location.search
            ? `?return=${encodeURIComponent(window.location.search)}`
            : '';

    const isCards = mode === 'cards';
    const brandName =
        options.product_lines.find((p) => p.slug === filters.product_line)
            ?.name ?? null;
    const seriesName = filters.series ?? null;
    const setName =
        options.sets.find((s) => s.slug === filters.set)?.name ?? null;
    const subsetName = filters.subset ? subsetLabel(filters.subset) : null;

    const heading =
        seo?.heading ??
        (mode === 'brands'
            ? 'Browse the catalog'
            : mode === 'series'
              ? (brandName ?? 'Browse')
              : mode === 'sets'
                ? (seriesName ?? brandName ?? 'Browse')
                : mode === 'subsets'
                  ? (setName ?? 'Browse')
                  : (subsetName
                    ? `${setName ?? ''}${setName ? ' · ' : ''}${subsetName}`
                    : (setName ??
                      brandName ??
                      (filters.q
                          ? `“${filters.q}”`
                          : 'Browse the catalog'))));

    const countNoun =
        mode === 'series'
            ? 'series'
            : mode === 'sets'
              ? 'sets'
              : mode === 'subsets'
                ? 'subsets'
                : 'brands';
    const countLabel = isCards
        ? `${pagination.total.toLocaleString()} items`
        : `${tiles.length.toLocaleString()} ${countNoun}`;

    // Breadcrumb up the brand → series → set → subset path. Each crumb clears the
    // deeper filters; the last (current location) is rendered as plain text.
    const crumbs: { label: string; go?: () => void }[] = [];
    if (
        filters.product_line ||
        filters.series ||
        filters.set ||
        filters.subset
    ) {
        crumbs.push({ label: 'All brands', go: reset });
        if (brandName) {
            crumbs.push({
                label: brandName,
                go: () =>
                    update({
                        series: null,
                        set: null,
                        subset: null,
                        q: null,
                    }),
            });
        }
        if (seriesName) {
            crumbs.push({
                label: seriesName,
                go: () => update({ set: null, subset: null, q: null }),
            });
        }
        if (setName) {
            crumbs.push({
                label: setName,
                go: () => update({ subset: null, q: null }),
            });
        }
        if (subsetName) {
            crumbs.push({ label: subsetName });
        }
    }

    return (
        <>
            <Head title={seo?.title ?? 'Browse catalog'} />

            <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                {crumbs.length > 0 && (
                    <nav className="mb-2 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
                        {crumbs.map((crumb, i) => {
                            const isLast = i === crumbs.length - 1;

                            return (
                                <span
                                    key={i}
                                    className="flex items-center gap-1.5"
                                >
                                    {i > 0 && <span>/</span>}
                                    {crumb.go && !isLast ? (
                                        <button
                                            type="button"
                                            onClick={crumb.go}
                                            className="hover:text-foreground"
                                        >
                                            {crumb.label}
                                        </button>
                                    ) : (
                                        <span className="text-foreground">
                                            {crumb.label}
                                        </span>
                                    )}
                                </span>
                            );
                        })}
                    </nav>
                )}

                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        {heading}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {countLabel}
                    </p>
                    {blurb && (
                        <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                            {blurb}
                        </p>
                    )}
                </div>

                {/* Top bar: search + controls */}
                <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search by name or number…"
                            className="pl-9"
                            aria-label="Search catalog"
                        />
                    </div>

                    {isCards && (
                    <div className="flex items-center gap-2">
                        {/* Mobile filter sheet */}
                        <Sheet>
                            <SheetTrigger asChild>
                                <Button
                                    variant="outline"
                                    className="lg:hidden"
                                    size="sm"
                                >
                                    <SlidersHorizontal className="size-4" />
                                    Filters
                                    {activeCount > 0 && (
                                        <Badge
                                            variant="secondary"
                                            className="ml-1"
                                        >
                                            {activeCount}
                                        </Badge>
                                    )}
                                </Button>
                            </SheetTrigger>
                            <SheetContent side="left" className="w-80">
                                <SheetHeader>
                                    <SheetTitle>Filters</SheetTitle>
                                </SheetHeader>
                                <div className="space-y-4 overflow-y-auto px-4 pb-6">
                                    <FilterControls
                                        options={options}
                                        filters={filters}
                                        onChange={update}
                                    />
                                </div>
                            </SheetContent>
                        </Sheet>

                        {/* Sort */}
                        <Select
                            value={filters.sort}
                            onValueChange={(value) =>
                                update({
                                    sort: value,
                                    direction: DESC_BY_DEFAULT[value] ?? 'asc',
                                })
                            }
                        >
                            <SelectTrigger size="sm" className="w-[8.5rem]">
                                <ArrowDownUp className="size-4" />
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {SORTS.map((s) => (
                                    <SelectItem key={s.value} value={s.value}>
                                        {s.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Button
                            variant="outline"
                            size="sm"
                            aria-label="Toggle sort direction"
                            onClick={() =>
                                update({
                                    direction:
                                        filters.direction === 'asc'
                                            ? 'desc'
                                            : 'asc',
                                })
                            }
                        >
                            {filters.direction === 'asc' ? '↑' : '↓'}
                        </Button>

                        {/* Group by base */}
                        <Button
                            variant={filters.group ? 'default' : 'outline'}
                            size="sm"
                            aria-pressed={filters.group}
                            onClick={() => update({ group: !filters.group })}
                        >
                            <Layers className="size-4" />
                            <span className="hidden sm:inline">Group</span>
                        </Button>

                        {/* Grid / list view */}
                        <ToggleGroup
                            type="single"
                            value={view}
                            onValueChange={(v) =>
                                v && setView(v as 'grid' | 'list')
                            }
                            variant="outline"
                            size="sm"
                        >
                            <ToggleGroupItem
                                value="grid"
                                aria-label="Grid view"
                            >
                                <LayoutGrid className="size-4" />
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="list"
                                aria-label="List view"
                            >
                                <List className="size-4" />
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                    )}
                </div>

                {!isCards && (
                    <TilesView
                        mode={mode}
                        tiles={tiles}
                        tileLanguages={tileLanguages}
                        filters={filters}
                        onChange={update}
                    />
                )}

                {isCards && (
                <div className="flex gap-8">
                    {/* Desktop filter rail */}
                    <aside className="hidden w-60 shrink-0 lg:block">
                        <div className="sticky top-20 space-y-4">
                            <div className="flex items-center justify-between">
                                <h2 className="text-sm font-semibold">
                                    Filters
                                </h2>
                                {activeCount > 0 && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={reset}
                                        className="h-auto p-1 text-xs"
                                    >
                                        Clear
                                    </Button>
                                )}
                            </div>
                            <FilterControls
                                options={options}
                                filters={filters}
                                onChange={update}
                            />
                        </div>
                    </aside>

                    {/* Results */}
                    <div className="min-w-0 flex-1">
                        {activeCount > 0 && (
                            <ActiveChips
                                filters={filters}
                                onClear={(key) =>
                                    key === 'q'
                                        ? (setQ(''), update({ q: null }))
                                        : update({ [key]: null })
                                }
                                onReset={reset}
                            />
                        )}

                        {items.length === 0 ? (
                            <div className="rounded-lg border border-dashed border-border py-20 text-center">
                                <p className="text-sm text-muted-foreground">
                                    No items match your filters.
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-4"
                                    onClick={reset}
                                >
                                    Clear filters
                                </Button>
                            </div>
                        ) : view === 'grid' ? (
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                                {items.map((item) => (
                                    <CardTile
                                        key={item.id}
                                        item={item}
                                        returnTo={returnTo}
                                        canAdd={canAdd}
                                        gradingCompanies={gradingCompanies}
                                    />
                                ))}
                            </div>
                        ) : (
                            <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                                {items.map((item) => (
                                    <ListRow
                                        key={item.id}
                                        item={item}
                                        returnTo={returnTo}
                                        canAdd={canAdd}
                                        gradingCompanies={gradingCompanies}
                                    />
                                ))}
                            </div>
                        )}

                        <InfiniteFooter
                            pagination={pagination}
                            filters={filters}
                            hasItems={items.length > 0}
                        />
                    </div>
                </div>
                )}
            </div>
        </>
    );
}

function TilesView({
    mode,
    tiles,
    tileLanguages,
    filters,
    onChange,
}: {
    mode: 'brands' | 'series' | 'sets' | 'subsets' | 'cards';
    tiles: BrowseTile[];
    tileLanguages: string[];
    filters: CatalogFilters;
    onChange: (partial: Partial<CatalogFilters>) => void;
}) {
    return (
        <div>
            {(mode === 'series' || mode === 'sets') &&
                tileLanguages.length > 1 && (
                <div className="mb-6 flex items-center gap-2">
                    <span className="text-sm text-muted-foreground">
                        Language
                    </span>
                    <Select
                        value={filters.language ?? ALL}
                        onValueChange={(v) =>
                            onChange({ language: v === ALL ? null : v })
                        }
                    >
                        <SelectTrigger size="sm" className="w-44">
                            <SelectValue placeholder="All languages" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All languages</SelectItem>
                            {tileLanguages.map((l) => (
                                <SelectItem key={l} value={l}>
                                    {languageLabel(l)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}

            {tiles.length === 0 ? (
                <div className="rounded-lg border border-dashed border-border py-20 text-center text-sm text-muted-foreground">
                    Nothing here yet.
                </div>
            ) : (
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {tiles.map((tile) => (
                        <TileCard
                            key={`${tile.kind}-${tile.slug}`}
                            tile={tile}
                            onOpen={() =>
                                onChange(
                                    tile.kind === 'brand'
                                        ? { product_line: tile.slug }
                                        : tile.kind === 'series'
                                          ? { series: tile.slug }
                                          : tile.kind === 'set'
                                            ? { set: tile.slug }
                                            : tile.set_slug
                                              ? {
                                                    set: tile.set_slug,
                                                    subset: null,
                                                }
                                              : { subset: tile.slug },
                                )
                            }
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function TileCard({ tile, onOpen }: { tile: BrowseTile; onOpen: () => void }) {
    return (
        <button
            type="button"
            onClick={onOpen}
            className="group flex flex-col overflow-hidden rounded-lg border border-border bg-card text-left transition-colors hover:border-ring"
        >
            <div className="flex aspect-[4/3] items-center justify-center overflow-hidden bg-muted p-4">
                {tile.thumb ? (
                    <img
                        src={tile.thumb}
                        alt={tile.name}
                        loading="lazy"
                        className="size-full object-contain transition-transform group-hover:scale-105"
                    />
                ) : (
                    <span className="text-xs text-muted-foreground">
                        No image
                    </span>
                )}
            </div>
            <div className="space-y-0.5 p-3">
                <p className="truncate text-sm font-medium" title={tile.name}>
                    {tile.name}
                </p>
                <p className="text-xs text-muted-foreground">
                    {tile.kind === 'set' && tile.released_at
                        ? `${tile.released_at.slice(0, 4)} · `
                        : ''}
                    {tile.count.toLocaleString()}{' '}
                    {tile.kind === 'series' ? 'sets' : 'cards'}
                    {tile.kind === 'set' && tile.language
                        ? ` · ${languageLabel(tile.language)}`
                        : ''}
                </p>
            </div>
        </button>
    );
}

function FilterControls({
    options,
    filters,
    onChange,
}: {
    options: CatalogFilterOptions;
    filters: CatalogFilters;
    onChange: (partial: Partial<CatalogFilters>) => void;
}) {
    const selects: {
        key: keyof CatalogFilters;
        label: string;
        opts: { value: string; label: string }[];
    }[] = [
        {
            key: 'item_type',
            label: 'Type',
            opts: options.item_types.map((v) => ({
                value: v,
                label: humanize(v),
            })),
        },
        {
            key: 'set',
            label: 'Set',
            opts: options.sets.map((s) => ({ value: s.slug, label: s.name })),
        },
        {
            key: 'rarity',
            label: 'Rarity',
            opts: options.rarities.map((v) => ({ value: v, label: v })),
        },
        {
            key: 'variant',
            label: 'Variant',
            opts: options.variants.map((v) => ({
                value: v,
                label: humanize(v),
            })),
        },
        {
            key: 'language',
            label: 'Language',
            opts: options.languages.map((v) => ({
                value: v,
                label: languageLabel(v),
            })),
        },
    ];

    return (
        <div className="space-y-4">
            {selects
                .filter((s) => s.opts.length > 0)
                .map((s) => (
                    <div key={s.key} className="space-y-1.5">
                        <label className="text-xs font-medium text-muted-foreground">
                            {s.label}
                        </label>
                        <Select
                            value={(filters[s.key] as string) || ALL}
                            onValueChange={(value) =>
                                onChange({
                                    [s.key]: value === ALL ? null : value,
                                })
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue
                                    placeholder={`All ${s.label.toLowerCase()}`}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    All {s.label.toLowerCase()}
                                </SelectItem>
                                {s.opts.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                ))}
        </div>
    );
}

function ActiveChips({
    filters,
    onClear,
    onReset,
}: {
    filters: CatalogFilters;
    onClear: (key: keyof CatalogFilters) => void;
    onReset: () => void;
}) {
    const chips = ACTIVE_KEYS.filter((k) => filters[k]).map((k) => ({
        key: k,
        label: `${k === 'q' ? 'Search' : humanize(k)}: ${filters[k]}`,
    }));

    return (
        <div className="mb-4 flex flex-wrap items-center gap-2">
            {chips.map((chip) => (
                <Badge
                    key={chip.key}
                    variant="secondary"
                    className="gap-1 py-1"
                >
                    {chip.label}
                    <button
                        type="button"
                        onClick={() => onClear(chip.key)}
                        aria-label={`Clear ${chip.key}`}
                        className="ml-0.5 rounded-full hover:text-foreground"
                    >
                        <X className="size-3" />
                    </button>
                </Badge>
            ))}
            <Button
                variant="ghost"
                size="sm"
                onClick={onReset}
                className="h-auto p-1 text-xs"
            >
                Clear all
            </Button>
        </div>
    );
}

function ItemImage({
    item,
    className,
}: {
    item: CatalogItem;
    className?: string;
}) {
    if (!item.image_url) {
        return (
            <div
                className={cn(
                    'flex items-center justify-center bg-muted text-muted-foreground',
                    className,
                )}
            >
                <span className="text-xs">No image</span>
            </div>
        );
    }

    return (
        <img
            src={item.image_url}
            alt={item.name}
            loading="lazy"
            className={className}
        />
    );
}

function VariantBadges({ item }: { item: CatalogItem }) {
    return (
        <div className="flex flex-wrap gap-1">
            {item.rarity && (
                <Badge variant="secondary" className="text-[10px]">
                    {item.rarity}
                </Badge>
            )}
            {item.variant && item.variant !== 'normal' && (
                <Badge variant="outline" className="text-[10px]">
                    {humanize(item.variant)}
                </Badge>
            )}
            {item.variants_count && item.variants_count > 1 && (
                <Badge className="text-[10px]">
                    {item.variants_count} printings
                </Badge>
            )}
        </div>
    );
}

function AddButton({
    item,
    gradingCompanies,
    className,
}: {
    item: CatalogItem;
    gradingCompanies: GradingCompanyOption[];
    className?: string;
}) {
    return (
        <AddToCollectionDialog
            catalogItemId={item.id}
            gradingCompanies={gradingCompanies}
            postOptions={{ preserveScroll: true, reset: ['items'] }}
            trigger={
                <button
                    type="button"
                    aria-label={`Add ${item.name} to collection`}
                    title="Add to collection"
                    className={cn(
                        'flex size-8 items-center justify-center rounded-full bg-background/85 text-foreground ring-1 ring-border backdrop-blur transition-colors hover:bg-primary hover:text-primary-foreground hover:ring-primary',
                        className,
                    )}
                >
                    <Plus className="size-4" />
                </button>
            }
        />
    );
}

function CardTile({
    item,
    returnTo,
    canAdd,
    gradingCompanies,
}: {
    item: CatalogItem;
    returnTo: string;
    canAdd: boolean;
    gradingCompanies: GradingCompanyOption[];
}) {
    return (
        <div className="group relative overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-ring">
            <Link href={`/catalog/${item.id}${returnTo}`} className="block">
                <div className="aspect-[3/4] overflow-hidden bg-muted">
                    <ItemImage
                        item={item}
                        className="size-full object-contain transition-transform group-hover:scale-105"
                    />
                </div>
                <div className="space-y-1.5 p-3">
                    <p
                        className="truncate text-sm font-medium"
                        title={item.name}
                    >
                        {item.name}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {item.set?.code ?? item.set?.name}
                        {item.number ? ` · ${item.number}` : ''}
                    </p>
                    {item.market_value && (
                        <PriceTag value={item.market_value} />
                    )}
                    <VariantBadges item={item} />
                </div>
            </Link>
            {canAdd && (
                <div className="absolute top-2 right-2">
                    <AddButton item={item} gradingCompanies={gradingCompanies} />
                </div>
            )}
        </div>
    );
}

function ListRow({
    item,
    returnTo,
    canAdd,
    gradingCompanies,
}: {
    item: CatalogItem;
    returnTo: string;
    canAdd: boolean;
    gradingCompanies: GradingCompanyOption[];
}) {
    return (
        <div className="flex items-center bg-card hover:bg-accent/40">
            <Link
                href={`/catalog/${item.id}${returnTo}`}
                className="flex min-w-0 flex-1 items-center gap-3 p-3"
            >
                <div className="h-16 w-12 shrink-0 overflow-hidden rounded bg-muted">
                    <ItemImage
                        item={item}
                        className="size-full object-contain"
                    />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{item.name}</p>
                    <p className="text-xs text-muted-foreground">
                        {item.set?.name}
                        {item.number ? ` · ${item.number}` : ''}
                        {item.language
                            ? ` · ${languageLabel(item.language)}`
                            : ''}
                    </p>
                    <div className="mt-1">
                        <VariantBadges item={item} />
                    </div>
                </div>
                {item.market_value && (
                    <PriceTag value={item.market_value} className="shrink-0" />
                )}
            </Link>
            {canAdd && (
                <div className="pr-3">
                    <AddButton item={item} gradingCompanies={gradingCompanies} />
                </div>
            )}
        </div>
    );
}

function InfiniteFooter({
    pagination,
    filters,
    hasItems,
}: {
    pagination: Pagination;
    filters: CatalogFilters;
    hasItems: boolean;
}) {
    if (pagination.has_more) {
        return (
            <WhenVisible
                always
                buffer={400}
                params={{
                    only: ['items', 'pagination'],
                    data: {
                        ...buildQuery(filters),
                        page: pagination.page + 1,
                    },
                    preserveUrl: true,
                }}
                fallback={null}
            >
                <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                    <Spinner className="size-4" />
                    Loading more…
                </div>
            </WhenVisible>
        );
    }

    if (hasItems && pagination.total > pagination.per_page) {
        return (
            <p className="py-10 text-center text-sm text-muted-foreground">
                That's all {pagination.total.toLocaleString()} items.
            </p>
        );
    }

    return null;
}
