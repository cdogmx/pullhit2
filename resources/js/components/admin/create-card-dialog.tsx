import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { Combobox } from '@/components/admin/combobox';
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
import { languageLabel } from '@/lib/format';
import type { AdminCardCreateOptions } from '@/types';

const humanize = (s: string) =>
    s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/**
 * Admin: hand-create a single card under a set. The card inherits its vertical +
 * brand from the set; the server re-validates facets and computes the identity
 * hash (so this is a safe upsert — re-adding an existing card won't duplicate it).
 */
export function CreateCardDialog({
    options,
    open,
    onOpenChange,
}: {
    options: AdminCardCreateOptions;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        set_id: '' as number | string,
        name: '',
        number: '',
        language: 'en',
        variant: 'normal',
        rarity: '',
        illustrator: '',
        hp: '' as number | string,
        type: '',
        primary_image_path: '',
    });

    useEffect(() => {
        if (open) {
            form.setData('set_id', options.sets[0]?.id ?? '');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/cards', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Card created.');
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>New card</DialogTitle>
                        <DialogDescription>
                            Add a single card to a set.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3 py-4">
                        <Field label="Set" error={form.errors.set_id}>
                            <Combobox
                                value={String(form.data.set_id)}
                                onChange={(v) =>
                                    form.setData('set_id', Number(v))
                                }
                                placeholder="Select a set"
                                searchPlaceholder="Search sets…"
                                options={options.sets.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>

                        <Field label="Name" error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="Charizard ex"
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Number" error={form.errors.number}>
                                <Input
                                    value={form.data.number}
                                    onChange={(e) =>
                                        form.setData('number', e.target.value)
                                    }
                                    placeholder="199"
                                />
                            </Field>
                            <Field label="Rarity" error={form.errors.rarity}>
                                <Input
                                    value={form.data.rarity}
                                    onChange={(e) =>
                                        form.setData('rarity', e.target.value)
                                    }
                                    placeholder="Double Rare"
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field
                                label="Language"
                                error={form.errors.language}
                            >
                                <Select
                                    value={form.data.language}
                                    onValueChange={(v) =>
                                        form.setData('language', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.languages.map((l) => (
                                            <SelectItem key={l} value={l}>
                                                {languageLabel(l)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field label="Variant" error={form.errors.variant}>
                                <Select
                                    value={form.data.variant}
                                    onValueChange={(v) =>
                                        form.setData('variant', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.variants.map((v) => (
                                            <SelectItem key={v} value={v}>
                                                {humanize(v)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Illustrator">
                                <Input
                                    value={form.data.illustrator}
                                    onChange={(e) =>
                                        form.setData(
                                            'illustrator',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="HP" error={form.errors.hp}>
                                <Input
                                    type="number"
                                    min={0}
                                    value={form.data.hp}
                                    onChange={(e) =>
                                        form.setData('hp', e.target.value)
                                    }
                                />
                            </Field>
                        </div>

                        <Field label="Type">
                            <Input
                                value={form.data.type}
                                onChange={(e) =>
                                    form.setData('type', e.target.value)
                                }
                                placeholder="Fire"
                            />
                        </Field>

                        <Field
                            label="Image (optional)"
                            error={form.errors.primary_image_path}
                        >
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
                            Create card
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
