import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
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
import { ImageUploadField } from '@/components/admin/image-upload-field';
import { languageLabel } from '@/lib/format';
import type { AdminSet, CatalogItem } from '@/types';

const humanize = (s: string) =>
    s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const MAJOR_RETAILERS = [
    'Target',
    'Walmart',
    'Costco',
    'Amazon',
    'Best Buy',
    'GameStop',
    "Sam's Club",
    'Pokémon Center',
    'Other',
];

type RetailerLink = { retailer: string; url: string; price: string };

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
        retailer_links: [] as RetailerLink[],
        image_url: '',
    });

    useEffect(() => {
        if (item) {
            form.setDefaults({
                name: item.name,
                sealed_type: String(item.attributes?.sealed_type ?? 'booster_box'),
                language: String(item.attributes?.language ?? 'en'),
                pack_count: (item.attributes?.pack_count as number) ?? '',
                price: '',
                msrp: dollars(item.msrp),
                released_at: item.released_at ?? '',
                retailer_links: (item.retailer_links ?? []).map((l) => ({
                    retailer: l.retailer,
                    url: l.url,
                    price: dollars(l.price_cents),
                })),
                image_url: item.image_url ?? '',
            });
            form.reset();
        } else if (set) {
            form.setDefaults({
                name: `${set.name} Booster Box`,
                sealed_type: 'booster_box',
                language: set.language ?? 'en',
                pack_count: '',
                price: '',
                msrp: '',
                released_at: set.released_at ?? '',
                retailer_links: [],
                image_url: '',
            });
            form.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [set?.id, item?.id]);

    const links = form.data.retailer_links;
    const addLink = () =>
        form.setData('retailer_links', [
            ...links,
            { retailer: 'Target', url: '', price: '' },
        ]);
    const updateLink = (i: number, key: keyof RetailerLink, value: string) =>
        form.setData(
            'retailer_links',
            links.map((l, idx) => (idx === i ? { ...l, [key]: value } : l)),
        );
    const removeLink = (i: number) =>
        form.setData(
            'retailer_links',
            links.filter((_, idx) => idx !== i),
        );

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

                        <div className="grid gap-1.5">
                            <Label className="text-xs">
                                Retailer links (each with its own price)
                            </Label>
                            {links.map((link, i) => (
                                <div key={i} className="flex items-center gap-2">
                                    <Select
                                        value={link.retailer}
                                        onValueChange={(v) =>
                                            updateLink(i, 'retailer', v)
                                        }
                                    >
                                        <SelectTrigger className="w-32 shrink-0">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {MAJOR_RETAILERS.map((r) => (
                                                <SelectItem key={r} value={r}>
                                                    {r}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Input
                                        placeholder="https://…"
                                        value={link.url}
                                        onChange={(e) =>
                                            updateLink(i, 'url', e.target.value)
                                        }
                                        className="min-w-0 flex-1"
                                    />
                                    <Input
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        placeholder="$"
                                        value={link.price}
                                        onChange={(e) =>
                                            updateLink(i, 'price', e.target.value)
                                        }
                                        className="w-20 shrink-0"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="shrink-0"
                                        onClick={() => removeLink(i)}
                                        aria-label="Remove link"
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addLink}
                                className="justify-self-start"
                            >
                                <Plus className="size-4" /> Add retailer link
                            </Button>
                        </div>

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
