import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import type { CatalogItem } from '@/types';

const NONE = '__none__';

const VARIANTS: [string, string][] = [
    ['normal', 'Normal'],
    ['holo', 'Holo'],
    ['reverse_holo', 'Reverse Holo'],
];

const EDITIONS: [string, string][] = [
    [NONE, '— None —'],
    ['unlimited', 'Unlimited'],
    ['shadowless', 'Shadowless'],
    ['first_edition', '1st Edition'],
];

const LANGUAGES = ['en', 'ja', 'ko', 'zh-CN', 'zh-TW', 'fr', 'de', 'it', 'es', 'pt'];

/**
 * "Suggest an edit" — any logged-in user can propose corrections to a card's
 * fields + a note; an admin reviews before anything changes.
 */
export function SuggestEditDialog({
    item,
    trigger,
}: {
    item: CatalogItem;
    trigger: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const a = item.attributes ?? {};

    const form = useForm({
        name: item.name ?? '',
        number: item.number ?? '',
        rarity: (a.rarity as string) ?? '',
        variant: (a.variant as string) ?? 'normal',
        edition: (a.edition as string) ?? NONE,
        language: (a.language as string) ?? 'en',
        illustrator: (a.illustrator as string) ?? '',
        note: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            edition: data.edition === NONE ? '' : data.edition,
        }));
        form.post(`/catalog/${item.id}/suggestions`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Thanks — your edit was sent for review.');
                form.reset('note');
                setOpen(false);
            },
        });
    };

    const changesError = (form.errors as Record<string, string | undefined>)
        .changes;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Suggest an edit</DialogTitle>
                        <DialogDescription>
                            Propose corrections — an admin reviews before they go
                            live.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3 py-4">
                        <Field label="Name">
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Number">
                                <Input
                                    value={form.data.number}
                                    onChange={(e) =>
                                        form.setData('number', e.target.value)
                                    }
                                    placeholder="4"
                                />
                            </Field>
                            <Field label="Rarity">
                                <Input
                                    value={form.data.rarity}
                                    onChange={(e) =>
                                        form.setData('rarity', e.target.value)
                                    }
                                    placeholder="Rare Holo"
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Variant">
                                <Picker
                                    value={form.data.variant}
                                    onChange={(v) => form.setData('variant', v)}
                                    options={VARIANTS}
                                />
                            </Field>
                            <Field label="Edition">
                                <Picker
                                    value={form.data.edition}
                                    onChange={(v) => form.setData('edition', v)}
                                    options={EDITIONS}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Language">
                                <Picker
                                    value={form.data.language}
                                    onChange={(v) => form.setData('language', v)}
                                    options={LANGUAGES.map((l) => [l, languageLabel(l)])}
                                />
                            </Field>
                            <Field label="Illustrator">
                                <Input
                                    value={form.data.illustrator}
                                    onChange={(e) =>
                                        form.setData('illustrator', e.target.value)
                                    }
                                />
                            </Field>
                        </div>

                        <Field label="What's wrong? (optional)">
                            <Textarea
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                placeholder="e.g. the image is the wrong printing, or the rarity is incorrect."
                            />
                        </Field>
                        {changesError && (
                            <p className="text-xs text-red-600">
                                {changesError}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            Submit for review
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label className="text-xs">{label}</Label>
            {children}
        </div>
    );
}

function Picker({
    value,
    onChange,
    options,
}: {
    value: string;
    onChange: (v: string) => void;
    options: [string, string][];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {options.map(([v, label]) => (
                    <SelectItem key={v} value={v}>
                        {label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
