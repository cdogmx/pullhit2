import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
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
import { languageLabel } from '@/lib/format';
import type { AdminSet } from '@/types';

const humanize = (s: string) =>
    s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/** Admin: add a sealed product (booster box, ETB, …) to a set. */
export function AddSealedDialog({
    set,
    sealedTypes,
    languages,
    open,
    onOpenChange,
}: {
    set: AdminSet | null;
    sealedTypes: string[];
    languages: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        name: '',
        sealed_type: 'booster_box',
        language: 'en',
        pack_count: '' as number | string,
        price: '' as number | string,
        image_url: '',
    });

    useEffect(() => {
        if (set) {
            form.setDefaults({
                name: `${set.name} Booster Box`,
                sealed_type: 'booster_box',
                language: set.language ?? 'en',
                pack_count: '',
                price: '',
                image_url: '',
            });
            form.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [set?.id]);

    if (!set) {
        return null;
    }

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/admin/sets/${set.id}/sealed`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Sealed product added.');
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Add sealed product</DialogTitle>
                        <DialogDescription>
                            {set.name}
                            {set.language
                                ? ` · ${languageLabel(set.language)}`
                                : ''}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3 py-4">
                        <Field label="Name" error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-3">
                            <Field
                                label="Sealed type"
                                error={form.errors.sealed_type}
                            >
                                <Select
                                    value={form.data.sealed_type}
                                    onValueChange={(v) =>
                                        form.setData('sealed_type', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sealedTypes.map((t) => (
                                            <SelectItem key={t} value={t}>
                                                {humanize(t)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
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
                                        {languages.map((l) => (
                                            <SelectItem key={l} value={l}>
                                                {languageLabel(l)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Pack count (optional)">
                                <Input
                                    type="number"
                                    min={1}
                                    value={form.data.pack_count}
                                    onChange={(e) =>
                                        form.setData('pack_count', e.target.value)
                                    }
                                    placeholder="36"
                                />
                            </Field>
                            <Field
                                label="Market price ($, optional)"
                                error={form.errors.price}
                            >
                                <Input
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={form.data.price}
                                    onChange={(e) =>
                                        form.setData('price', e.target.value)
                                    }
                                    placeholder="129.99"
                                />
                            </Field>
                        </div>

                        <Field
                            label="Image URL (optional)"
                            error={form.errors.image_url}
                        >
                            <Input
                                value={form.data.image_url}
                                onChange={(e) =>
                                    form.setData('image_url', e.target.value)
                                }
                                placeholder="https://…"
                            />
                        </Field>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            Add sealed product
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
