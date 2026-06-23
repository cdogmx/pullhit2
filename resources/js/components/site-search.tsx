import { router } from '@inertiajs/react';
import { ImageOff, Loader2, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

type BrandHit = { name: string; thumb: string | null; url: string };
type SetHit = {
    name: string;
    brand: string;
    thumb: string | null;
    url: string;
};
type CardHit = {
    name: string;
    set: string | null;
    thumb: string | null;
    url: string;
};

type Results = { brands: BrandHit[]; sets: SetHit[]; cards: CardHit[] };

type Flat = {
    url: string;
    thumb: string | null;
    title: string;
    subtitle: string | null;
    rounded: boolean;
};

const EMPTY: Results = { brands: [], sets: [], cards: [] };

/**
 * Public header type-ahead. Debounced fetch to /search/suggest, results grouped
 * brand → set → card with thumbnails. Click (or Enter on a highlighted row) goes
 * to that page; Enter with nothing highlighted runs the full /browse search.
 */
export function SiteSearch({ className }: { className?: string }) {
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [results, setResults] = useState<Results>(EMPTY);
    const [active, setActive] = useState(-1);
    const ref = useRef<HTMLDivElement>(null);

    // Flattened, in render order — drives keyboard navigation.
    const flat = useMemo<Flat[]>(
        () => [
            ...results.brands.map((b) => ({
                url: b.url,
                thumb: b.thumb,
                title: b.name,
                subtitle: 'Brand',
                rounded: true,
            })),
            ...results.sets.map((s) => ({
                url: s.url,
                thumb: s.thumb,
                title: s.name,
                subtitle: s.brand,
                rounded: true,
            })),
            ...results.cards.map((c) => ({
                url: c.url,
                thumb: c.thumb,
                title: c.name,
                subtitle: c.set,
                rounded: false,
            })),
        ],
        [results],
    );

    const hasResults =
        results.brands.length + results.sets.length + results.cards.length > 0;

    // Debounced suggest fetch; aborts the in-flight request on each keystroke.
    // All state changes happen inside the timer/promise callbacks (never
    // synchronously in the effect body) to avoid cascading renders.
    useEffect(() => {
        const term = q.trim();
        const controller = new AbortController();

        const t = setTimeout(() => {
            if (term.length < 2) {
                setResults(EMPTY);
                setLoading(false);

                return;
            }

            setLoading(true);

            fetch(`/search/suggest?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((r) => r.json())
                .then((data: Results) => {
                    setResults(data);
                    setActive(-1);
                    setLoading(false);
                })
                .catch(() => {
                    /* aborted or failed — ignore */
                });
        }, 180);

        return () => {
            clearTimeout(t);
            controller.abort();
        };
    }, [q]);

    // Close on outside click.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onDown);

        return () => document.removeEventListener('mousedown', onDown);
    }, [open]);

    const go = (url: string) => {
        setOpen(false);
        setQ('');
        router.visit(url);
    };

    const submit = () => {
        const term = q.trim();

        if (term.length > 0) {
            go(`/browse?q=${encodeURIComponent(term)}`);
        }
    };

    const onKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Escape') {
            setOpen(false);
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => Math.min(i + 1, flat.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(i - 1, -1));
        } else if (e.key === 'Enter') {
            e.preventDefault();

            if (active >= 0 && flat[active]) {
                go(flat[active].url);
            } else {
                submit();
            }
        }
    };

    let index = -1; // running offset to map grouped rows onto the flat list

    return (
        <div ref={ref} className={cn('relative', className)}>
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <input
                    value={q}
                    onChange={(e) => {
                        setQ(e.target.value);
                        setOpen(true);

                        // Show the spinner immediately (the fetch is debounced),
                        // so the panel never flashes "No matches" mid-type.
                        if (e.target.value.trim().length >= 2) {
                            setLoading(true);
                        }
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={onKeyDown}
                    placeholder="Search cards, sets, brands…"
                    className="h-9 w-full rounded-md border border-input bg-background/60 pr-3 pl-9 text-sm transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    aria-label="Search the catalog"
                />
                {loading && (
                    <Loader2 className="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                )}
            </div>

            {open && q.trim().length >= 2 && (
                <div className="absolute left-0 z-50 mt-1 w-full overflow-hidden rounded-md border border-border bg-popover text-popover-foreground shadow-md">
                    <div className="max-h-[70vh] overflow-y-auto p-1">
                        {!hasResults ? (
                            <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                                {loading
                                    ? 'Searching…'
                                    : `No matches for “${q.trim()}”.`}
                            </p>
                        ) : (
                            <>
                                {results.brands.length > 0 && (
                                    <Group label="Brands">
                                        {results.brands.map((b) => {
                                            index++;

                                            return (
                                                <Row
                                                    key={`b-${b.url}`}
                                                    thumb={b.thumb}
                                                    title={b.name}
                                                    subtitle="Brand"
                                                    rounded
                                                    active={index === active}
                                                    onSelect={() => go(b.url)}
                                                />
                                            );
                                        })}
                                    </Group>
                                )}
                                {results.sets.length > 0 && (
                                    <Group label="Sets">
                                        {results.sets.map((s) => {
                                            index++;

                                            return (
                                                <Row
                                                    key={`s-${s.url}`}
                                                    thumb={s.thumb}
                                                    title={s.name}
                                                    subtitle={s.brand}
                                                    rounded
                                                    active={index === active}
                                                    onSelect={() => go(s.url)}
                                                />
                                            );
                                        })}
                                    </Group>
                                )}
                                {results.cards.length > 0 && (
                                    <Group label="Cards">
                                        {results.cards.map((c) => {
                                            index++;

                                            return (
                                                <Row
                                                    key={`c-${c.url}`}
                                                    thumb={c.thumb}
                                                    title={c.name}
                                                    subtitle={c.set}
                                                    active={index === active}
                                                    onSelect={() => go(c.url)}
                                                />
                                            );
                                        })}
                                    </Group>
                                )}
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

function Group({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="py-1">
            <p className="px-2 py-1 text-xs font-medium text-muted-foreground">
                {label}
            </p>
            {children}
        </div>
    );
}

function Row({
    thumb,
    title,
    subtitle,
    rounded,
    active,
    onSelect,
}: {
    thumb: string | null;
    title: string;
    subtitle: string | null;
    rounded?: boolean;
    active: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            // Use mousedown so the click registers before the input's blur.
            onMouseDown={(e) => {
                e.preventDefault();
                onSelect();
            }}
            className={cn(
                'flex w-full items-center gap-3 rounded-sm px-2 py-1.5 text-left',
                active && 'bg-accent text-accent-foreground',
            )}
        >
            <span
                className={cn(
                    'flex size-9 shrink-0 items-center justify-center overflow-hidden border border-border bg-muted',
                    rounded ? 'rounded-md' : 'rounded',
                )}
            >
                {thumb ? (
                    <img
                        src={thumb}
                        alt=""
                        loading="lazy"
                        className="size-full object-contain"
                    />
                ) : (
                    <ImageOff className="size-4 text-muted-foreground" />
                )}
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">
                    {title}
                </span>
                {subtitle && (
                    <span className="block truncate text-xs text-muted-foreground">
                        {subtitle}
                    </span>
                )}
            </span>
        </button>
    );
}
