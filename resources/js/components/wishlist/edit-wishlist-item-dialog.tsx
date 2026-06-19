import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

/** The subset of a wishlist item the edit modal reads. */
export type EditableWishlistItem = {
    id: number;
    name: string;
    target_price: number | null;
    notes: string | null;
};

export type MoveTarget = { id: number; name: string };

const KEEP = '__keep__';

export function EditWishlistItemDialog({
    item,
    wishlists,
    open,
    onOpenChange,
}: {
    item: EditableWishlistItem | null;
    wishlists: MoveTarget[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                {item && (
                    <EditWishlistForm
                        key={item.id}
                        item={item}
                        wishlists={wishlists}
                        onOpenChange={onOpenChange}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditWishlistForm({
    item,
    wishlists,
    onOpenChange,
}: {
    item: EditableWishlistItem;
    wishlists: MoveTarget[];
    onOpenChange: (open: boolean) => void;
}) {
    const [target, setTarget] = useState(
        item.target_price != null ? String(item.target_price / 100) : '',
    );
    const [notes, setNotes] = useState(item.notes ?? '');
    const [moveTo, setMoveTo] = useState(KEEP);
    const [saving, setSaving] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const dollars = target.trim();
        const cents = dollars === '' ? null : Math.round(parseFloat(dollars) * 100);

        const payload: Record<string, string | number | boolean | null> = {
            target_price: cents != null && Number.isFinite(cents) ? cents : null,
            notes: notes.trim() === '' ? null : notes.trim().slice(0, 1000),
        };

        if (moveTo !== KEEP) {
            payload.wishlist_id = Number(moveTo);
        }

        setSaving(true);
        router.patch(`/wishlist/${item.id}`, payload, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Wishlist item updated.');
                onOpenChange(false);
            },
            onFinish: () => setSaving(false),
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle>Edit wishlist item</DialogTitle>
                <DialogDescription>{item.name}</DialogDescription>
            </DialogHeader>

            <div className="grid gap-4 py-4">
                <div className="grid gap-1.5">
                    <Label className="text-xs">Target price</Label>
                    <div className="relative">
                        <span className="absolute top-1/2 left-2.5 -translate-y-1/2 text-sm text-muted-foreground">
                            $
                        </span>
                        <Input
                            type="number"
                            min={0}
                            step={0.01}
                            value={target}
                            onChange={(e) => setTarget(e.target.value)}
                            placeholder="Alert me at…"
                            className="pl-6"
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        We'll flag the card when its value drops to this.
                    </p>
                </div>

                <div className="grid gap-1.5">
                    <Label className="text-xs">Notes</Label>
                    <Textarea
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Optional"
                        maxLength={1000}
                    />
                </div>

                {wishlists.length > 0 && (
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Move to wishlist</Label>
                        <Select value={moveTo} onValueChange={setMoveTo}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={KEEP}>Keep here</SelectItem>
                                {wishlists.map((w) => (
                                    <SelectItem key={w.id} value={String(w.id)}>
                                        {w.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
            </div>

            <DialogFooter>
                <Button type="submit" disabled={saving}>
                    Save changes
                </Button>
            </DialogFooter>
        </form>
    );
}
