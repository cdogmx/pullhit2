import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { ImageUploadField } from '@/components/admin/image-upload-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
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
import type { AdminCard } from '@/types';

const VARIANTS = ['normal', 'holo', 'reverse_holo', '1st_edition', 'unlimited'];

/** Admin edit of one card. PATCHes /admin/cards/{id}; re-hashes server-side. */
export function EditCardDialog({
    card,
    open,
    onOpenChange,
}: {
    card: AdminCard | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        name: '',
        number: '',
        rarity: '',
        variant: 'normal',
        illustrator: '',
        hp: '' as number | string,
        type: '',
        primary_image_path: '',
    });

    useEffect(() => {
        if (card) {
            form.setDefaults({
                name: card.name ?? '',
                number: card.number ?? '',
                rarity: card.rarity ?? '',
                variant: card.variant ?? 'normal',
                illustrator: card.illustrator ?? '',
                hp: card.hp ?? '',
                type: card.type ?? '',
                primary_image_path: card.primary_image_path ?? '',
            });
            form.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [card?.id]);

    if (!card) {
        return null;
    }

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(`/admin/cards/${card.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Card updated.');
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Edit card</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-3 py-4">
                        <Field label="Name" error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Number">
                                <Input
                                    value={form.data.number}
                                    onChange={(e) => form.setData('number', e.target.value)}
                                />
                            </Field>
                            <Field label="Rarity">
                                <Input
                                    value={form.data.rarity}
                                    onChange={(e) => form.setData('rarity', e.target.value)}
                                />
                            </Field>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Variant">
                                <Select
                                    value={form.data.variant}
                                    onValueChange={(v) => form.setData('variant', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {VARIANTS.map((v) => (
                                            <SelectItem key={v} value={v}>
                                                {v}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field label="HP">
                                <Input
                                    type="number"
                                    value={form.data.hp}
                                    onChange={(e) => form.setData('hp', e.target.value)}
                                />
                            </Field>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Illustrator">
                                <Input
                                    value={form.data.illustrator}
                                    onChange={(e) => form.setData('illustrator', e.target.value)}
                                />
                            </Field>
                            <Field label="Type">
                                <Input
                                    value={form.data.type}
                                    onChange={(e) => form.setData('type', e.target.value)}
                                />
                            </Field>
                        </div>
                        <Field label="Image" error={form.errors.primary_image_path}>
                            <ImageUploadField
                                value={form.data.primary_image_path}
                                onChange={(u) =>
                                    form.setData('primary_image_path', u)
                                }
                            />
                        </Field>
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

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label className="text-xs">{label}</Label>
            {children}
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}
