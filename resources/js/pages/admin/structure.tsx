import { Head, useForm } from '@inertiajs/react';
import { ChevronRight, Image as ImageIcon, Layers, Library, Pencil } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ImageUploadField } from '@/components/admin/image-upload-field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    value: string | null;
    image: string | null;
    grouped: boolean;
    set_count: number;
    sets: SetRow[];
};

type Brand = {
    id: number;
    brand: string;
    slug: string;
    set_count: number;
    series_count: number;
    series: SeriesGroup[];
};

type Props = { brands: Brand[] };

/** The series being edited: which brand, its current value, label, image. */
type RenameTarget = {
    brandId: number;
    from: string | null;
    label: string;
    count: number;
    image: string | null;
};

export default function AdminStructure({ brands }: Props) {
    const [rename, setRename] = useState<RenameTarget | null>(null);

    return (
        <>
            <Head title="Admin · Catalog structure" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <Explainer />

                <div className="space-y-3">
                    {brands.map((b) => (
                        <BrandTree key={b.slug} brand={b} onRename={setRename} />
                    ))}
                </div>
            </div>

            <RenameSeriesDialog
                target={rename}
                onOpenChange={(o) => !o && setRename(null)}
            />
        </>
    );
}

function RenameSeriesDialog({
    target,
    onOpenChange,
}: {
    target: RenameTarget | null;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        product_line_id: 0,
        from: '' as string | null,
        to: '',
        logo_url: '',
    });

    useEffect(() => {
        if (target) {
            form.setData({
                product_line_id: target.brandId,
                from: target.from,
                to: target.from ?? '',
                logo_url: target.image ?? '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target?.brandId, target?.from]);

    if (!target) {
        return null;
    }

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/structure/series', {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash;

                if (flash?.error) {
                    toast.error(flash.error);
                } else {
                    toast.success(flash?.success ?? 'Series updated.');
                }

                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Edit series</DialogTitle>
                        <DialogDescription>
                            Applies to all {target.count} set
                            {target.count === 1 ? '' : 's'} in this series. Rename
                            to an existing series to merge; leave the name blank to
                            ungroup.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-1.5">
                            <Label className="text-xs">Name</Label>
                            <Input
                                autoFocus
                                value={form.data.to}
                                onChange={(e) =>
                                    form.setData('to', e.target.value)
                                }
                                placeholder="e.g. Mega Evolution"
                            />
                            {form.errors.to && (
                                <p className="text-xs text-red-600">
                                    {form.errors.to}
                                </p>
                            )}
                        </div>

                        <div className="grid gap-1.5">
                            <Label className="text-xs">
                                Series image (optional)
                            </Label>
                            <ImageUploadField
                                value={form.data.logo_url}
                                onChange={(u) => form.setData('logo_url', u)}
                            />
                            <p className="text-xs text-muted-foreground">
                                Shown on the series tile in browse. Falls back to a
                                card from the series when empty.
                            </p>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
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
                        body="NOT a separate record — just the “Series” text field on each set. Sets that share the same value group under one tile in browse (e.g. every set with series “Mega Evolution”). To group a set, set its Series (on the Sets page); to rename, merge, or give a series its own tile image, use its Edit button below."
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

function BrandTree({
    brand,
    onRename,
}: {
    brand: Brand;
    onRename: (target: RenameTarget) => void;
}) {
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
                            {group.image ? (
                                <img
                                    src={group.image}
                                    alt={group.series}
                                    className="size-6 shrink-0 rounded object-contain"
                                />
                            ) : (
                                <Layers
                                    className={
                                        group.grouped
                                            ? 'size-4 text-primary'
                                            : 'size-4 text-muted-foreground/50'
                                    }
                                />
                            )}
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
                            {group.grouped && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        onRename({
                                            brandId: brand.id,
                                            from: group.value,
                                            label: group.series,
                                            count: group.set_count,
                                            image: group.image,
                                        })
                                    }
                                    className="ml-1 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                >
                                    {group.image ? (
                                        <ImageIcon className="size-3" />
                                    ) : (
                                        <Pencil className="size-3" />
                                    )}{' '}
                                    Edit
                                </button>
                            )}
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
