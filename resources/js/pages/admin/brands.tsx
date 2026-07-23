import { Head, router } from '@inertiajs/react';
import { ImageOff, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { DataTable } from '@/components/admin/data-table';
import type { DataTableColumn } from '@/components/admin/data-table';
import { EditBrandDialog } from '@/components/admin/edit-brand-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AdminBrand, AdminVertical } from '@/types';

type Props = { brands: AdminBrand[]; verticals: AdminVertical[] };

const ALL = '__all__';

export default function AdminBrands({ brands, verticals }: Props) {
    const [editing, setEditing] = useState<AdminBrand | null>(null);
    const [creating, setCreating] = useState(false);
    const [vertical, setVertical] = useState('');
    const [confirmDelete, setConfirmDelete] = useState<AdminBrand | null>(null);
    const [busy, setBusy] = useState(false);

    const shown = useMemo(
        () =>
            vertical ? brands.filter((b) => b.vertical === vertical) : brands,
        [brands, vertical],
    );

    const remove = (brand: AdminBrand) => {
        setBusy(true);
        router.delete(`/admin/brands/${brand.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Deleted ${brand.name}.`);
                setConfirmDelete(null);
            },
            onFinish: () => setBusy(false),
        });
    };

    const columns: DataTableColumn<AdminBrand>[] = [
        {
            key: 'name',
            header: 'Brand',
            sortable: true,
            value: (b) => b.name,
            cell: (b) => (
                <div className="flex items-center gap-3">
                    <div className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-muted">
                        {b.logo_url ? (
                            <img
                                src={b.logo_url}
                                alt={b.name}
                                className="size-full object-contain"
                            />
                        ) : (
                            <ImageOff className="size-4 text-muted-foreground" />
                        )}
                    </div>
                    <div className="min-w-0">
                        <p className="font-medium">{b.name}</p>
                        {b.description && (
                            <p className="line-clamp-1 text-xs text-muted-foreground">
                                {b.description}
                            </p>
                        )}
                    </div>
                </div>
            ),
        },
        {
            key: 'vertical',
            header: 'Vertical',
            sortable: true,
            value: (b) => b.vertical,
            cell: (b) => (
                <span className="text-muted-foreground">{b.vertical}</span>
            ),
        },
        {
            key: 'sets',
            header: 'Sets',
            align: 'right',
            sortable: true,
            value: (b) => b.sets,
        },
        {
            key: 'items',
            header: 'Cards',
            align: 'right',
            sortable: true,
            value: (b) => b.items,
            cell: (b) => b.items.toLocaleString(),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            cellClassName: 'whitespace-nowrap',
            cell: (b) => (
                <>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setEditing(b)}
                    >
                        <Pencil className="size-4" /> Edit
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setConfirmDelete(b)}
                        className="text-muted-foreground hover:text-red-600"
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </>
            ),
        },
    ];

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

                <Card>
                    <CardContent className="pt-6">
                        <DataTable
                            data={shown}
                            columns={columns}
                            getRowKey={(b) => b.id}
                            searchPlaceholder="Search brands…"
                            initialSort={{ key: 'name', dir: 'asc' }}
                            toolbar={
                                verticals.length > 1 ? (
                                    <Select
                                        value={vertical || ALL}
                                        onValueChange={(v) =>
                                            setVertical(v === ALL ? '' : v)
                                        }
                                    >
                                        <SelectTrigger className="w-40">
                                            <SelectValue placeholder="All verticals" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ALL}>
                                                All verticals
                                            </SelectItem>
                                            {verticals.map((v) => (
                                                <SelectItem
                                                    key={v.id}
                                                    value={v.name}
                                                >
                                                    {v.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : null
                            }
                        />
                    </CardContent>
                </Card>
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

            <ConfirmDialog
                open={confirmDelete !== null}
                onOpenChange={(o) => !o && setConfirmDelete(null)}
                title={
                    confirmDelete
                        ? `Delete ${confirmDelete.name}?`
                        : 'Delete brand?'
                }
                description={
                    confirmDelete &&
                    (confirmDelete.sets > 0 || confirmDelete.items > 0)
                        ? `This permanently deletes ${confirmDelete.sets} set(s) and ${confirmDelete.items.toLocaleString()} card(s), and removes them from every user's collection and wishlist.`
                        : 'This cannot be undone.'
                }
                confirmLabel="Delete brand"
                destructive
                busy={busy}
                onConfirm={() => confirmDelete && remove(confirmDelete)}
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
