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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { AdminBrand, AdminVertical } from '@/types';

/**
 * Admin: create a brand (pass `brand={null}` + `verticals`) or edit one (pass
 * `brand`). The vertical is only chosen on create — it's fixed once a brand
 * exists (it's coupled to the card-attribute schema).
 */
export function EditBrandDialog({
    brand,
    verticals = [],
    open,
    onOpenChange,
}: {
    brand: AdminBrand | null;
    verticals?: AdminVertical[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const creating = brand === null;

    const form = useForm({
        vertical_id: '' as number | string,
        name: '',
        description: '',
        logo_url: '',
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        if (brand) {
            form.setData({
                vertical_id: '',
                name: brand.name,
                description: brand.description ?? '',
                logo_url: brand.logo_url ?? '',
            });
        } else {
            form.setData({
                vertical_id: verticals[0]?.id ?? '',
                name: '',
                description: '',
                logo_url: '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, brand?.id]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(creating ? 'Brand created.' : 'Brand updated.');
                form.reset();
                onOpenChange(false);
            },
        };

        if (brand) {
            form.patch(`/admin/brands/${brand.id}`, opts);
        } else {
            form.post('/admin/brands', opts);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>
                            {creating ? 'New brand' : 'Edit brand'}
                        </DialogTitle>
                        <DialogDescription>
                            {creating
                                ? 'Create a product line under a vertical.'
                                : brand?.slug}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3 py-4">
                        {creating && (
                            <div className="grid gap-1.5">
                                <Label className="text-xs">Vertical</Label>
                                <Select
                                    value={String(form.data.vertical_id)}
                                    onValueChange={(v) =>
                                        form.setData('vertical_id', Number(v))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a vertical" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {verticals.map((v) => (
                                            <SelectItem
                                                key={v.id}
                                                value={String(v.id)}
                                            >
                                                {v.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.vertical_id && (
                                    <p className="text-xs text-red-600">
                                        {form.errors.vertical_id}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="grid gap-1.5">
                            <Label className="text-xs">Name</Label>
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="Pokémon"
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
                            {creating ? 'Create brand' : 'Save changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
