import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { AdminBrand } from '@/types';

/** Admin: edit a brand's display name, logo, and description. */
export function EditBrandDialog({
    brand,
    open,
    onOpenChange,
}: {
    brand: AdminBrand | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        name: '',
        description: '',
        logo_url: '',
    });

    useEffect(() => {
        if (open && brand) {
            form.setData({
                name: brand.name,
                description: brand.description ?? '',
                logo_url: brand.logo_url ?? '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, brand?.id]);

    if (!brand) {
        return null;
    }

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(`/admin/brands/${brand.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Brand updated.');
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Edit brand</DialogTitle>
                        <DialogDescription>{brand.slug}</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3 py-4">
                        <div className="grid gap-1.5">
                            <Label className="text-xs">Name</Label>
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            {form.errors.name && (
                                <p className="text-xs text-red-600">
                                    {form.errors.name}
                                </p>
                            )}
                        </div>

                        <div className="grid gap-1.5">
                            <Label className="text-xs">Description</Label>
                            <Textarea
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Shown on the brand's browse page."
                            />
                        </div>

                        <div className="grid gap-1.5">
                            <Label className="text-xs">Logo</Label>
                            <ImageUploadField
                                value={form.data.logo_url}
                                onChange={(u) => form.setData('logo_url', u)}
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            Save changes
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
