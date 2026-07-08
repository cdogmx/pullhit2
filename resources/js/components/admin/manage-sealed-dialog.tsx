import { Package, Pencil, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatMoney } from '@/lib/format';
import type { AdminSet, CatalogItem } from '@/types';

const humanize = (s: string | null | undefined) =>
    (s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/**
 * Admin: manage a set's sealed products — list them with edit/delete, and add
 * new ones. Editing/adding hands off to AddSealedDialog via the callbacks.
 */
export function ManageSealedDialog({
    set,
    open,
    onOpenChange,
    onAdd,
    onEdit,
    onDelete,
}: {
    set: AdminSet | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onAdd: () => void;
    onEdit: (item: CatalogItem) => void;
    onDelete: (item: CatalogItem) => void;
}) {
    if (!set) {
        return null;
    }

    const sealed = set.sealed ?? [];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Sealed products · {set.name}</DialogTitle>
                    <DialogDescription>
                        {sealed.length === 0
                            ? 'No sealed products yet.'
                            : `${sealed.length} sealed product${sealed.length === 1 ? '' : 's'}.`}
                    </DialogDescription>
                </DialogHeader>

                {sealed.length > 0 && (
                    <ul className="space-y-2 py-2">
                        {sealed.map((item) => (
                            <li
                                key={item.id}
                                className="flex items-center justify-between gap-3 rounded-md border border-border p-2"
                            >
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">
                                        {item.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {humanize(
                                            item.attributes?.sealed_type as
                                                | string
                                                | undefined,
                                        ) || 'Unknown type'}
                                        {item.msrp != null
                                            ? ` · MSRP ${formatMoney(item.msrp)}`
                                            : ''}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-1">
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => onEdit(item)}
                                    >
                                        <Pencil className="size-4" /> Edit
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => onDelete(item)}
                                        className="text-muted-foreground hover:text-red-600"
                                        aria-label="Delete sealed product"
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <DialogFooter>
                    <Button onClick={onAdd}>
                        <Package className="size-4" /> Add sealed product
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
