import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { CatalogItem } from '@/types';

type Props = { item: CatalogItem };

const humanize = (value: string): string =>
    value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/** Facet keys surfaced as labelled detail rows, in display order. */
const DETAIL_FACETS: { key: string; label: string }[] = [
    { key: 'rarity', label: 'Rarity' },
    { key: 'variant', label: 'Printing' },
    { key: 'illustrator', label: 'Illustrator' },
    { key: 'hp', label: 'HP' },
    { key: 'type', label: 'Type' },
    { key: 'sealed_type', label: 'Sealed type' },
    { key: 'pack_count', label: 'Packs' },
];

export default function Show({ item }: Props) {
    const attributes = item.attributes ?? {};
    const printings = item.variants ?? [];

    const facetRows = DETAIL_FACETS.filter(
        (f) => attributes[f.key] !== undefined && attributes[f.key] !== null,
    ).map((f) => ({
        label: f.label,
        value:
            f.key === 'variant'
                ? humanize(String(attributes[f.key]))
                : String(attributes[f.key]),
    }));

    return (
        <>
            <Head title={item.name} />

            <div className="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                <Link
                    href="/browse"
                    className="mb-6 inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Back to browse
                </Link>

                <div className="grid gap-8 md:grid-cols-[minmax(0,360px)_1fr]">
                    {/* Image */}
                    <div className="mx-auto w-full max-w-[320px] md:mx-0">
                        <div className="overflow-hidden rounded-xl border border-border bg-muted">
                            {item.image_url ? (
                                <img
                                    src={item.image_url}
                                    alt={item.name}
                                    className="aspect-[3/4] w-full object-contain"
                                />
                            ) : (
                                <div className="flex aspect-[3/4] items-center justify-center text-sm text-muted-foreground">
                                    No image
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Info */}
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">
                            {item.set?.name}
                            {item.number ? ` · ${item.number}` : ''}
                            {item.language
                                ? ` · ${item.language.toUpperCase()}`
                                : ''}
                        </p>
                        <h1 className="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">
                            {item.name}
                        </h1>

                        <div className="mt-3 flex flex-wrap gap-1.5">
                            <Badge variant="outline">
                                {humanize(item.item_type)}
                            </Badge>
                            {item.rarity && (
                                <Badge variant="secondary">{item.rarity}</Badge>
                            )}
                            {item.variant && (
                                <Badge>{humanize(item.variant)}</Badge>
                            )}
                        </div>

                        {/* Price slot — populated by the valuation engine (Phase 3). */}
                        <div className="mt-6 rounded-lg border border-dashed border-border p-4">
                            <p className="text-xs font-medium text-muted-foreground">
                                Market value
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Pricing &amp; confidence coming soon.
                            </p>
                        </div>

                        {/* Facet details */}
                        {facetRows.length > 0 && (
                            <dl className="mt-6 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                                {facetRows.map((row) => (
                                    <div key={row.label}>
                                        <dt className="text-xs text-muted-foreground">
                                            {row.label}
                                        </dt>
                                        <dd className="font-medium">
                                            {row.value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </div>
                </div>

                {/* Printings of this card */}
                {printings.length > 1 && (
                    <section className="mt-12">
                        <h2 className="mb-3 text-lg font-semibold">
                            Printings ({printings.length})
                        </h2>
                        <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                            {printings.map((printing) => {
                                const isCurrent = printing.id === item.id;
                                const variant = printing.attributes?.variant
                                    ? humanize(
                                          String(printing.attributes.variant),
                                      )
                                    : (printing.variant ?? '—');

                                return (
                                    <Link
                                        key={printing.id}
                                        href={`/catalog/${printing.id}`}
                                        className={cn(
                                            'flex items-center gap-3 p-3 transition-colors hover:bg-accent/40',
                                            isCurrent && 'bg-accent/60',
                                        )}
                                        preserveScroll
                                    >
                                        <div className="h-14 w-10 shrink-0 overflow-hidden rounded bg-muted">
                                            {printing.image_url && (
                                                <img
                                                    src={printing.image_url}
                                                    alt={printing.name}
                                                    className="size-full object-contain"
                                                />
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium">
                                                {variant}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {printing.number}
                                                {printing.rarity
                                                    ? ` · ${printing.rarity}`
                                                    : ''}
                                            </p>
                                        </div>
                                        {isCurrent && (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                Viewing
                                            </Badge>
                                        )}
                                    </Link>
                                );
                            })}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
