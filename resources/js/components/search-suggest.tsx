import { router } from '@inertiajs/react';
import { ImageOff, Loader2, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';

type BrandHit = { name: string; thumb: string | null; url: string };
type SetHit = {
    name: string;
    brand: string;
    series: string | null;
    language: string | null;
    code: string | null;
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

/** "Pokémon · Scarlet & Violet · EN · SSP" — brand + series + language + code. */
function setSubtitle(s: SetHit): string {
    return [s.brand, s.series, s.language?.toUpperCase(), s.code]
        .filter(Boolean)
        .join(' · ');
}

/**
 * Public catalog type-ahead. Owns the debounced fetch to /search/suggest, the
 * portaled results dropdown (grouped brand → set → card with thumbnails), and
 * keyboard navigation. Clicking a suggestion — or Enter on a highlighted row —
 * navigates straight to that page; Enter with nothing highlighted calls
 * `onSubmit(term)` to run a full search. This is the ONE place that behaviour
 * lives, shared by the site header, the homepage hero, and the browse page.
 *
 * The dropdown is deliberately lightweight: typing never triggers a full catalog
 * reload, only this small suggest request. The heavy /browse query runs once, on
 * submit.
 *
 * `variant` selects the input chrome. The dropdown is identical for all.
 */
export function SearchSuggest({
    value,
    onChange,
    onSubmit,
    variant = 'header',
    placeholder = 'Search cards, sets, brands…',
    className,
    ariaLabel = 'Search the catalog',
    clearOnNavigate = true,
    scope,
}: {
    value: string;
    onChange: (value: string) => void;
    /** Enter (or the Search button) with no suggestion highlighted. */
    onSubmit: (term: string) => void;
    variant?: 'header' | 'hero' | 'browse';
    placeholder?: string;
    className?: string;
    ariaLabel?: string;
    /** Clear the input after navigating to a suggestion / submitting. */
    clearOnNavigate?: boolean;
    /**
     * Confine suggestions to a brand (and optionally one of its series) — what
     * browse passes so the dropdown never leaves the section being browsed.
     */
    scope?: { product_line?: string | null; series?: string | null };
}) {
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [results, setResults] = useState<Results>(EMPTY);
    const [active, setActive] = useState(-1);
    // Anchored panel position (the dropdown renders in a portal so no ancestor's
    // overflow/stacking context can clip it — e.g. the hero's overflow-hidden).
    const [pos, setPos] = useState<{
        top: number;
        left: number;
        width: number;
    } | null>(null);
    const ref = useRef<HTMLDivElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);

    const q = value;
    // Read off the object so the fetch effect can depend on the values, not on
    // a prop identity that changes every render.
    const scopeLine = scope?.product_line ?? '';
    const scopeSeries = scope?.series ?? '';

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
                subtitle: setSubtitle(s),
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

            const params = new URLSearchParams({ q: term });

            if (scopeLine) {
                params.set('product_line', scopeLine);
            }

            if (scopeSeries) {
                params.set('series', scopeSeries);
            }

            fetch(`/search/suggest?${params.toString()}`, {
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
    }, [q, scopeLine, scopeSeries]);

    // Anchor the portal panel under the input; keep it pinned on scroll/resize.
    useEffect(() => {
        if (!open) {
            return;
        }

        const reposition = () => {
            const el = ref.current;

            if (el) {
                const r = el.getBoundingClientRect();
                setPos({ top: r.bottom + 4, left: r.left, width: r.width });
            }
        };

        // rAF (not a synchronous call) so the first position is set off the
        // effect body, then track viewport changes while open.
        const raf = requestAnimationFrame(reposition);
        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, true);

        return () => {
            cancelAnimationFrame(raf);
            window.removeEventListener('resize', reposition);
            window.removeEventListener('scroll', reposition, true);
        };
    }, [open]);

    // Close on click outside both the input and the (portaled) panel.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onDown = (e: MouseEvent) => {
            const t = e.target as Node;

            if (!ref.current?.contains(t) && !panelRef.current?.contains(t)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onDown);

        return () => document.removeEventListener('mousedown', onDown);
    }, [open]);

    const go = (url: string) => {
        setOpen(false);

        if (clearOnNavigate) {
            onChange('');
        }

        router.visit(url);
    };

    const submit = () => {
        const term = q.trim();

        if (term.length === 0) {
            return;
        }

        setOpen(false);

        if (clearOnNavigate) {
            onChange('');
        }

        onSubmit(term);
    };

    const handleChange = (next: string) => {
        onChange(next);
        setOpen(true);

        // Show the spinner immediately (the fetch is debounced), so the panel
        // never flashes "No matches" mid-type.
        if (next.trim().length >= 2) {
            setLoading(true);
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
            {variant === 'hero' ? (
                <div className="flex w-full items-center gap-2 rounded-full bg-white p-1.5 shadow-xl ring-1 ring-black/5">
                    <Search className="ml-3 size-5 shrink-0 text-neutral-400" />
                    <input
                        type="search"
                        value={q}
                        onChange={(e) => handleChange(e.target.value)}
                        onFocus={() => setOpen(true)}
                        onKeyDown={onKeyDown}
                        placeholder={placeholder}
                        aria-label={ariaLabel}
                        className="min-w-0 flex-1 bg-transparent py-2 text-base text-neutral-900 outline-none placeholder:text-neutral-500"
                    />
                    {loading && (
                        <Loader2 className="size-5 shrink-0 animate-spin text-neutral-400" />
                    )}
                    <button
                        type="button"
                        onClick={submit}
                        className="shrink-0 rounded-full bg-[#111317] px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#111317]/90"
                    >
                        Search
                    </button>
                </div>
            ) : (
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        value={q}
                        onChange={(e) => handleChange(e.target.value)}
                        onFocus={() => setOpen(true)}
                        onKeyDown={onKeyDown}
                        placeholder={placeholder}
                        aria-label={ariaLabel}
                        className={cn(
                            'w-full rounded-md border border-input bg-background/60 pr-9 pl-9 text-sm transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            variant === 'browse' ? 'h-10' : 'h-9',
                        )}
                    />
                    {loading && (
                        <Loader2 className="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                    )}
                </div>
            )}

            {open &&
                q.trim().length >= 2 &&
                pos &&
                createPortal(
                    <div
                        ref={panelRef}
                        style={{
                            position: 'fixed',
                            top: pos.top,
                            left: pos.left,
                            width: pos.width,
                        }}
                        className="z-[60] overflow-hidden rounded-md border border-border bg-popover text-popover-foreground shadow-md"
                    >
                        <div className="max-h-[70vh] overflow-y-auto p-1">
                            {!hasResults ? (
                                <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                                    {loading
                                        ? 'Searching…'
                                        : `No matches — press Enter to search “${q.trim()}”.`}
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
                                                        active={
                                                            index === active
                                                        }
                                                        onSelect={() =>
                                                            go(b.url)
                                                        }
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
                                                        subtitle={setSubtitle(
                                                            s,
                                                        )}
                                                        rounded
                                                        active={
                                                            index === active
                                                        }
                                                        onSelect={() =>
                                                            go(s.url)
                                                        }
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
                                                        active={
                                                            index === active
                                                        }
                                                        onSelect={() =>
                                                            go(c.url)
                                                        }
                                                    />
                                                );
                                            })}
                                        </Group>
                                    )}
                                </>
                            )}
                        </div>
                    </div>,
                    document.body,
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
