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
import { languageLabel } from '@/lib/format';
import type { AdminSet, CatalogItem } from '@/types';

const humanize = (s: string) =>
    s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const dollars = (cents: number | null | undefined): string =>
    cents != null ? (cents / 100).toString() : '';

/**
 * Admin: add a sealed product to a set (pass `set`), or edit an existing one
 * (pass `item`). Same form, posts to create or patches to update.
 */
export function AddSealedDialog({
    set,
    item,
    sealedTypes,
    languages,
    open,
    onOpenChange,
}: {
    set?: AdminSet | null;
    item?: CatalogItem | null;
    sealedTypes: string[];
    languages: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const editing = Boolean(item);

    const form = useForm({
        name: '',
        sealed_type: 'booster_box',
        language: 'en',
        pack_count: '' as number | string,
        price: '' as number | string,
        msrp: '' as number | string,
        released_at: '',
        image_url: '',
    });

    useEffect(() => {
        // Prefill each time the dialog opens (it stays mounted on the card page).
        if (!open) {
            return;
        }

        if (item) {
            form.setData({
                name: item.name,
                sealed_type: String(item.attributes?.sealed_type ?? 'booster_box'),
                language: String(item.attributes?.language ?? 'en'),
                pack_count: (item.attributes?.pack_count as number) ?? '',
                price: '',
                msrp: dollars(item.msrp),
                released_at: item.released_at ?? '',
                image_url: item.image_url ?? '',
            });
        } else if (set) {
            form.setData({
                name: `${set.name} Booster Box`,
                sealed_type: 'booster_box',
                language: set.language ?? 'en',
                pack_count: '',
                price: '',
                msrp: '',
                released_at: set.released_at ?? '',
                image_url: '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, set?.id, item?.id]);

    if (!set && !item) {
        return null;
    }

    const contextName = item ? (item.set?.name ?? '') : (set?.name ?? '');
    const contextLang = item
        ? (item.attributes?.language as string | undefined)
        : set?.language;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    editing ? 'Sealed product updated.' : 'Sealed product added.',
                );
                onOpenChange(false);
            },
        };

        if (item) {
            form.patch(`/admin/sealed/${item.id}`, opts);
        } else if (set) {
            form.post(`/admin/sets/${set.id}/sealed`, opts);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? 'Edit sealed product' : 'Add sealed product'}
                        </DialogTitle>
                        <DialogDescription>
                            {contextName}
                            {contextLang
                                ? ` · ${languageLabel(contextLang)}`
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

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="MSRP ($, optional)" error={form.errors.msrp}>
                                <Input
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={form.data.msrp}
                                    onChange={(e) =>
                                        form.setData('msrp', e.target.value)
                                    }
                                    placeholder="161.64"
                                />
                            </Field>
                            <Field
                                label="Release date (optional)"
                                error={form.errors.released_at}
                            >
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
                            </Field>
                        </div>

                        <p className="text-xs text-muted-foreground">
                            "Where to buy" links are managed in the{' '}
                            <a
                                href="/admin/stock-alerts"
                                className="underline underline-offset-2 hover:text-foreground"
                            >
                                deals tracker
                            </a>{' '}
                            — attach this product there to show live retailer prices
                            and stock on its page.
                        </p>

                        <Field
                            label="Image (optional)"
                            error={form.errors.image_url}
                        >
                            <ImageUploadField
                                value={form.data.image_url}
                                onChange={(u) => form.setData('image_url', u)}
                            />
                        </Field>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            {editing ? 'Save changes' : 'Add sealed product'}
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
