import { Head, router } from '@inertiajs/react';
import { ImageOff, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EditBrandDialog } from '@/components/admin/edit-brand-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { AdminBrand, AdminVertical } from '@/types';

type Props = { brands: AdminBrand[]; verticals: AdminVertical[] };

export default function AdminBrands({ brands, verticals }: Props) {
    const [editing, setEditing] = useState<AdminBrand | null>(null);
    const [creating, setCreating] = useState(false);

    const remove = (brand: AdminBrand) => {
        const tail =
            brand.sets > 0 || brand.items > 0
                ? ` This permanently deletes ${brand.sets} set(s) and ${brand.items.toLocaleString()} card(s), and removes them from every user's collection and wishlist.`
                : '';

        if (!confirm(`Delete ${brand.name}?${tail}`)) {
            return;
        }

        router.delete(`/admin/brands/${brand.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Deleted ${brand.name}.`),
        });
    };

    return (
        <>
            <Head title="Admin · Brands" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <p className="text-sm text-muted-foreground">
                        Create, edit, and delete brands — each is a product line
                        under a vertical, shown on the browse tiles and landing
                        pages.
                    </p>
                    <Button size="sm" onClick={() => setCreating(true)}>
                        <Plus className="size-4" /> New brand
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {brands.map((brand) => (
                        <Card key={brand.id}>
                            <CardContent className="flex gap-4 pt-6">
                                <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-muted">
                                    {brand.logo_url ? (
                                        <img
                                            src={brand.logo_url}
                                            alt={brand.name}
                                            className="size-full object-contain"
                                        />
                                    ) : (
                                        <ImageOff className="size-6 text-muted-foreground" />
                                    )}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">
                                        {brand.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {brand.vertical} · {brand.sets} sets ·{' '}
                                        {brand.items.toLocaleString()} cards
                                    </p>
                                    {brand.description && (
                                        <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                                            {brand.description}
                                        </p>
                                    )}
                                    <div className="mt-2 flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditing(brand)}
                                        >
                                            <Pencil className="size-4" /> Edit
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="text-muted-foreground hover:text-red-600"
                                            onClick={() => remove(brand)}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>

            <EditBrandDialog
                brand={creating ? null : editing}
                verticals={verticals}
                open={editing !== null || creating}
                onOpenChange={(o) => {
                    if (!o) {
                        setEditing(null);
                        setCreating(false);
                    }
                }}
            />
        </>
    );
}

AdminBrands.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Brands', href: '/admin/brands' },
    ],
};
