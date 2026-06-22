import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ImageUploadField } from '@/components/admin/image-upload-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Admin: replace a card's image from the card page. Uploads/fetches via
 * /admin/images (stored in our bucket), then PATCHes the card's
 * primary_image_path — reusing the same flow as the admin cards list.
 */
export function EditCardImageDialog({
    catalogItemId,
    name,
    currentUrl,
    open,
    onOpenChange,
}: {
    catalogItemId: number;
    name: string;
    currentUrl: string | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                {/* Keyed so the draft re-seeds from the current image each open. */}
                {open && (
                    <EditForm
                        key={catalogItemId}
                        catalogItemId={catalogItemId}
                        name={name}
                        currentUrl={currentUrl}
                        onOpenChange={onOpenChange}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditForm({
    catalogItemId,
    name,
    currentUrl,
    onOpenChange,
}: {
    catalogItemId: number;
    name: string;
    currentUrl: string | null;
    onOpenChange: (open: boolean) => void;
}) {
    const [draft, setDraft] = useState(currentUrl ?? '');
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.patch(
            `/admin/cards/${catalogItemId}`,
            { primary_image_path: draft.trim() === '' ? null : draft.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Card image updated.');
                    onOpenChange(false);
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>Edit image</DialogTitle>
                <DialogDescription>{name}</DialogDescription>
            </DialogHeader>

            <div className="py-4">
                <ImageUploadField value={draft} onChange={setDraft} />
            </div>

            <DialogFooter>
                <Button onClick={save} disabled={saving}>
                    Save image
                </Button>
            </DialogFooter>
        </>
    );
}
