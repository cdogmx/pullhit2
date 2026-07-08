import { Head } from '@inertiajs/react';
import { ChevronRight, Layers, Library } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { languageLabel } from '@/lib/format';

type SetRow = {
    id: number;
    name: string;
    slug: string;
    language: string | null;
    released_at: string | null;
};

type SeriesGroup = {
    series: string;
    grouped: boolean;
    set_count: number;
    sets: SetRow[];
};

type Brand = {
    brand: string;
    slug: string;
    set_count: number;
    series_count: number;
    series: SeriesGroup[];
};

type Props = { brands: Brand[] };

export default function AdminStructure({ brands }: Props) {
    return (
        <>
            <Head title="Admin · Catalog structure" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <Explainer />

                <div className="space-y-3">
                    {brands.map((b) => (
                        <BrandTree key={b.slug} brand={b} />
                    ))}
                </div>
            </div>
        </>
    );
}

function Explainer() {
    return (
        <Card>
            <CardContent className="space-y-4 pt-6 text-sm">
                <div>
                    <h1 className="text-lg font-bold tracking-tight">
                        How the catalog is structured
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        A reference for how brands, series, sets, and cards fit
                        together — and how to manage each level.
                    </p>
                </div>

                {/* The hierarchy, left to right */}
                <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/40 p-3 font-medium">
                    {['Brand', 'Series', 'Set', 'Subset', 'Card'].map(
                        (level, i) => (
                            <span key={level} className="flex items-center gap-2">
                                {i > 0 && (
                                    <ChevronRight className="size-4 text-muted-foreground" />
                                )}
                                <Badge variant="secondary">{level}</Badge>
                            </span>
                        ),
                    )}
                </div>

                <dl className="grid gap-3 sm:grid-cols-2">
                    <Term
                        label="Brand"
                        body="A product line — Pokémon, One Piece, Lorcana. Managed under Brands. Feeds the card's identity, so it isn't editable on a set after the fact."
                    />
                    <Term
                        label="Series"
                        body="NOT a separate record — just the “Series” text field on each set. Sets that share the same value group under one tile in browse (e.g. every set with series “Mega Evolution”). To group sets, give them the same Series; to rename a group, edit that field on each member set."
                    />
                    <Term
                        label="Set"
                        body="A release (Surging Sparks, 151). Managed under Sets — create, edit (name, code, series, release date, description, logo), Re-sync, and manage its sealed products. A brand with only one series skips the series tier in browse."
                    />
                    <Term
                        label="Subset"
                        body="A gallery child of a set (Trainer Gallery, etc.), detected by name suffix. Auto-grouped — nothing to manage directly."
                    />
                    <Term
                        label="Card / Sealed"
                        body="Individual singles and sealed products within a set. Singles come from imports; sealed products are added per set via Sets → Sealed. Renaming a set is cosmetic — identity uses the set slug, so links never break."
                    />
                    <Term
                        label="Ungrouped sets"
                        body="A set with no Series value still works, but shows on its own rather than under a group tile. Give it a Series to nest it."
                    />
                </dl>
            </CardContent>
        </Card>
    );
}

function Term({ label, body }: { label: string; body: string }) {
    return (
        <div className="rounded-md border border-border p-3">
            <dt className="font-semibold">{label}</dt>
            <dd className="mt-1 text-muted-foreground">{body}</dd>
        </div>
    );
}

function BrandTree({ brand }: { brand: Brand }) {
    return (
        <details className="group rounded-lg border border-border bg-card">
            <summary className="flex cursor-pointer list-none items-center gap-3 p-4">
                <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-90" />
                <Library className="size-4 shrink-0 text-muted-foreground" />
                <span className="font-semibold">{brand.brand}</span>
                <span className="text-xs text-muted-foreground">
                    {brand.series_count} series · {brand.set_count} sets
                </span>
            </summary>

            <div className="space-y-3 border-t border-border p-4">
                {brand.series.map((group) => (
                    <div key={group.series}>
                        <div className="mb-1.5 flex items-center gap-2">
                            <Layers
                                className={
                                    group.grouped
                                        ? 'size-4 text-primary'
                                        : 'size-4 text-muted-foreground/50'
                                }
                            />
                            <span
                                className={
                                    group.grouped
                                        ? 'text-sm font-medium'
                                        : 'text-sm font-medium text-muted-foreground italic'
                                }
                            >
                                {group.series}
                            </span>
                            <Badge variant="outline" className="text-[10px]">
                                {group.set_count}
                            </Badge>
                        </div>
                        <div className="ml-6 flex flex-wrap gap-1.5">
                            {group.sets.map((s) => (
                                <a
                                    key={s.id}
                                    href={`/browse/${brand.slug}/${s.slug}`}
                                    className="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs transition-colors hover:border-primary/40 hover:bg-accent/50"
                                    title={s.released_at ?? undefined}
                                >
                                    {s.name}
                                    {s.language && s.language !== 'en' && (
                                        <span className="text-[10px] text-muted-foreground">
                                            {languageLabel(s.language)}
                                        </span>
                                    )}
                                </a>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </details>
    );
}

AdminStructure.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Structure', href: '/admin/structure' },
    ],
};
