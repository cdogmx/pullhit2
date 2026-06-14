import { Head } from '@inertiajs/react';
import { ImageOff, Pencil } from 'lucide-react';
import { useState } from 'react';
import { EditBrandDialog } from '@/components/admin/edit-brand-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { AdminBrand } from '@/types';

type Props = { brands: AdminBrand[] };

export default function AdminBrands({ brands }: Props) {
    const [editing, setEditing] = useState<AdminBrand | null>(null);

    return (
        <>
            <Head title="Admin · Brands" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <p className="text-sm text-muted-foreground">
                    Set a logo and description for each brand — shown on the
                    browse brand tiles and landing pages.
                </p>

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
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="mt-2"
                                        onClick={() => setEditing(brand)}
                                    >
                                        <Pencil className="size-4" /> Edit
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>

            <EditBrandDialog
                brand={editing}
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
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
