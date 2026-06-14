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
import type { AdminSet } from '@/types';

/** Admin: edit a set's name, code, release date, logo, and description. */
export function EditSetDialog({
    set,
    open,
    onOpenChange,
}: {
    set: AdminSet | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        name: '',
        code: '',
        released_at: '',
        description: '',
        logo_url: '',
    });

    useEffect(() => {
        if (open && set) {
            form.setData({
                name: set.name,
                code: set.code ?? '',
                released_at: set.released_at ?? '',
                description: set.description ?? '',
                logo_url: set.logo_url ?? '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, set?.id]);

    if (!set) {
        return null;
    }

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(`/admin/sets/${set.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Set updated.');
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Edit set</DialogTitle>
                        <DialogDescription>
                            {set.ptcgio_id}
                            {set.language ? ` · ${set.language}` : ''}
                        </DialogDescription>
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

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1.5">
                                <Label className="text-xs">Code</Label>
                                <Input
                                    value={form.data.code}
                                    onChange={(e) =>
                                        form.setData('code', e.target.value)
                                    }
                                    placeholder="SSP"
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label className="text-xs">Release date</Label>
                                <Input
                                    type="date"
                                    value={form.data.released_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'released_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>

                        <div className="grid gap-1.5">
                            <Label className="text-xs">Description</Label>
                            <Textarea
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Shown on the set's browse page."
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
