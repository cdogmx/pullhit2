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
import { languageLabel } from '@/lib/format';
import type { AdminOption, AdminSet } from '@/types';

/**
 * Admin: create a set (pass `set={null}` + `productLines` + `languages`) or edit
 * one (pass `set`). Both show every field; on edit, brand and language are shown
 * read-only (changing them would desync the set's cards without a migration),
 * while name, code, series, release date, description, and logo are editable.
 */
export function EditSetDialog({
    set,
    productLines = [],
    languages = [],
    open,
    onOpenChange,
}: {
    set: AdminSet | null;
    productLines?: AdminOption[];
    languages?: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const creating = set === null;

    const form = useForm({
        product_line_id: '' as number | string,
        name: '',
        code: '',
        language: 'en',
        series: '',
        released_at: '',
        description: '',
        logo_url: '',
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        if (set) {
            form.setData({
                product_line_id: '',
                name: set.name,
                code: set.code ?? '',
                language: set.language ?? 'en',
                series: set.series ?? '',
                released_at: set.released_at ?? '',
                description: set.description ?? '',
                logo_url: set.logo_url ?? '',
            });
        } else {
            form.setData({
                product_line_id: productLines[0]?.id ?? '',
                name: '',
                code: '',
                language: 'en',
                series: '',
                released_at: '',
                description: '',
                logo_url: '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, set?.id]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(creating ? 'Set created.' : 'Set updated.');
                form.reset();
                onOpenChange(false);
            },
        };

        if (set) {
            form.patch(`/admin/sets/${set.id}`, opts);
        } else {
            form.post('/admin/sets', opts);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>
                            {creating ? 'New set' : 'Edit set'}
                        </DialogTitle>
                        <DialogDescription>
                            {creating
                                ? 'Create a set under a brand. Add cards afterwards.'
                                : `${set?.ptcgio_id ?? ''}${set?.language ? ` · ${languageLabel(set.language)}` : ''}`}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3 py-4">
                        <div className="grid gap-1.5">
                            <Label className="text-xs">Brand</Label>
                            {creating ? (
                                <Select
                                    value={String(form.data.product_line_id)}
                                    onValueChange={(v) =>
                                        form.setData(
                                            'product_line_id',
                                            Number(v),
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a brand" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {productLines.map((p) => (
                                            <SelectItem
                                                key={p.id}
                                                value={String(p.id)}
                                            >
                                                {p.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <Input value={set?.brand ?? '—'} disabled />
                            )}
                            {form.errors.product_line_id && (
                                <p className="text-xs text-red-600">
                                    {form.errors.product_line_id}
                                </p>
                            )}
                        </div>

                        <div className="grid gap-1.5">
                            <Label className="text-xs">Name</Label>
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="Surging Sparks"
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

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1.5">
                                <Label className="text-xs">Language</Label>
                                {creating ? (
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
                                ) : (
                                    <Input
                                        value={
                                            set?.language
                                                ? languageLabel(set.language)
                                                : '—'
                                        }
                                        disabled
                                    />
                                )}
                            </div>
                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    Series (optional)
                                </Label>
                                <Input
                                    value={form.data.series}
                                    onChange={(e) =>
                                        form.setData('series', e.target.value)
                                    }
                                    placeholder="Scarlet & Violet"
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
                            {creating ? 'Create set' : 'Save changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
