import { Head, Link, router } from '@inertiajs/react';
import { ImageOff, Search, ShieldCheck, Tag } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

type Tile = {
    id: number;
    title: string;
    category: string;
    category_label: string;
    price_cents: number;
    currency: string;
    accepts_offers: boolean;
    accepts_escrow: boolean;
    photo: string | null;
    grade: string | null;
    grader: string | null;
    condition: string | null;
    seller: string | null;
    url: string;
};

type Option = { value: string; label: string };

type Props = {
    listings: Tile[];
    pagination: { page: number; last_page: number; total: number };
    filters: {
        q: string;
        category: string | null;
        product_line: string;
        set: string;
        grading_company: string;
        min_price: number | null;
        max_price: number | null;
        sort: string;
    };
    options: {
        categories: Option[];
        product_lines: { slug: string; name: string }[];
        sets: { slug: string; name: string }[];
        grading_companies: { slug: string; name: string }[];
    };
};

const ALL = '__all__';

export default function MarketplaceIndex({
    listings,
    pagination,
    filters,
    options,
}: Props) {
    const [q, setQ] = useState(filters.q);

    const apply = (changes: Record<string, string | number | null> = {}) =>
        router.get(
            '/marketplace',
            { ...filters, page: 1, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    useEffect(() => {
        if (q === filters.q) {
            return;
        }

        const id = window.setTimeout(() => apply({ q }), 350);

        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    return (
        <>
            <Head title="Marketplace" />
            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-5 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Marketplace
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Cards listed by people here.{' '}
                            {pagination.total.toLocaleString()} for sale.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/marketplace/new">List a card</Link>
                    </Button>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-end gap-3 rounded-xl border border-border p-3">
                    <div className="grid gap-1">
                        <Label htmlFor="q" className="text-xs">
                            Search
                        </Label>
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                id="q"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Card, set, number…"
                                className="h-9 w-56 pl-8"
                            />
                        </div>
                    </div>

                    <div className="grid gap-1">
                        <Label className="text-xs">Type</Label>
                        <Select
                            value={filters.category ?? ALL}
                            onValueChange={(v) =>
                                apply({ category: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="h-9 w-40">
                                <SelectValue placeholder="Any" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Any type</SelectItem>
                                {options.categories.map((c) => (
                                    <SelectItem key={c.value} value={c.value}>
                                        {c.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1">
                        <Label className="text-xs">Game</Label>
                        <Select
                            value={filters.product_line || ALL}
                            onValueChange={(v) =>
                                apply({
                                    product_line: v === ALL ? '' : v,
                                    set: '',
                                })
                            }
                        >
                            <SelectTrigger className="h-9 w-40">
                                <SelectValue placeholder="Any" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Any game</SelectItem>
                                {options.product_lines.map((p) => (
                                    <SelectItem key={p.slug} value={p.slug}>
                                        {p.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {options.sets.length > 0 && (
                        <div className="grid gap-1">
                            <Label className="text-xs">Set</Label>
                            <Select
                                value={filters.set || ALL}
                                onValueChange={(v) =>
                                    apply({ set: v === ALL ? '' : v })
                                }
                            >
                                <SelectTrigger className="h-9 w-48">
                                    <SelectValue placeholder="Any" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>Any set</SelectItem>
                                    {options.sets.map((s) => (
                                        <SelectItem key={s.slug} value={s.slug}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    <div className="grid gap-1">
                        <Label className="text-xs">Sort</Label>
                        <Select
                            value={filters.sort}
                            onValueChange={(v) => apply({ sort: v })}
                        >
                            <SelectTrigger className="h-9 w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="newest">Newest</SelectItem>
                                <SelectItem value="price_asc">
                                    Price: low to high
                                </SelectItem>
                                <SelectItem value="price_desc">
                                    Price: high to low
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* Grid */}
                {listings.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-border py-16 text-center">
                        <p className="text-muted-foreground">
                            Nothing matches those filters yet.
                        </p>
                        <Button asChild variant="outline" className="mt-3">
                            <Link href="/marketplace/new">
                                List the first one
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {listings.map((l) => (
                            <li key={l.id}>
                                <Link
                                    href={l.url}
                                    className="group flex h-full flex-col overflow-hidden rounded-xl border border-border transition hover:border-foreground/30"
                                >
                                    <div className="relative aspect-[5/7] bg-muted">
                                        {l.photo ? (
                                            <img
                                                src={l.photo}
                                                alt={l.title}
                                                loading="lazy"
                                                className="size-full object-cover transition group-hover:scale-[1.02]"
                                            />
                                        ) : (
                                            <div className="flex size-full items-center justify-center text-muted-foreground">
                                                <ImageOff className="size-6" />
                                            </div>
                                        )}
                                        {l.grade && (
                                            <Badge className="absolute top-2 left-2 uppercase">
                                                {l.grader} {l.grade}
                                            </Badge>
                                        )}
                                        {l.accepts_escrow && (
                                            <span
                                                title="Buy Protected available"
                                                className="absolute top-2 right-2 rounded-full bg-background/90 p-1"
                                            >
                                                <ShieldCheck className="size-4 text-emerald-600 dark:text-emerald-400" />
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex flex-1 flex-col gap-1 p-3">
                                        <p className="line-clamp-2 text-sm font-medium">
                                            {l.title}
                                        </p>
                                        <p className="mt-auto text-base font-semibold tabular-nums">
                                            {formatMoney(
                                                l.price_cents,
                                                l.currency,
                                            )}
                                        </p>
                                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <span>{l.category_label}</span>
                                            {l.condition && (
                                                <span>· {l.condition}</span>
                                            )}
                                            {l.accepts_offers && (
                                                <span
                                                    className="flex items-center gap-0.5"
                                                    title="Accepts offers"
                                                >
                                                    <Tag className="size-3" />
                                                </span>
                                            )}
                                        </p>
                                        {l.seller && (
                                            <p className="text-xs text-muted-foreground">
                                                @{l.seller}
                                            </p>
                                        )}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pagination.page <= 1}
                            onClick={() => apply({ page: pagination.page - 1 })}
                        >
                            Previous
                        </Button>
                        <span className="text-muted-foreground">
                            Page {pagination.page} of {pagination.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pagination.page >= pagination.last_page}
                            onClick={() => apply({ page: pagination.page + 1 })}
                        >
                            Next
                        </Button>
                    </div>
                )}

                <p
                    className={cn(
                        'rounded-lg border border-border bg-muted/40 p-3 text-xs text-muted-foreground',
                    )}
                >
                    CardFoo is a venue only and is not party to any transaction
                    between buyers and sellers. Never pay a stranger by Friends
                    &amp; Family. For protection, use Buy Protected.
                </p>
            </div>
        </>
    );
}
