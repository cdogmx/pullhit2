import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { ListTargetPicker } from '@/components/shared/list-target-picker';
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
import { cn } from '@/lib/utils';
import type { GradingCompanyOption } from '@/types';

const CONDITIONS: { value: string; label: string }[] = [
    { value: 'NM', label: 'Near Mint' },
    { value: 'LP', label: 'Lightly Played' },
    { value: 'MP', label: 'Moderately Played' },
    { value: 'HP', label: 'Heavily Played' },
    { value: 'DMG', label: 'Damaged' },
    { value: 'SEALED', label: 'Sealed' },
];

/**
 * "Add to collection" — opened from the catalog detail page. Records a holding
 * at a chosen priced state (raw condition OR graded company+grade) plus an
 * acquisition lot (quantity + unit cost). Money is entered in dollars and
 * converted to cents on submit.
 */
export function AddToCollectionDialog({
    catalogItemId,
    gradingCompanies,
    trigger,
    postOptions,
}: {
    catalogItemId: number;
    gradingCompanies: GradingCompanyOption[];
    /** Custom trigger element; defaults to a labelled "Add to collection" button. */
    trigger?: ReactNode;
    /**
     * Extra Inertia visit options merged into the submit (e.g. `reset: ['items']`
     * so the browse infinite-scroll list doesn't re-append on the redirect).
     */
    postOptions?: {
        preserveScroll?: boolean;
        preserveState?: boolean;
        reset?: string[];
        replace?: boolean;
    };
}) {
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<'raw' | 'graded'>('raw');

    const form = useForm({
        catalog_item_id: catalogItemId,
        collection_id: null as number | null,
        new_collection_name: '',
        condition: 'NM',
        grading_company_id: '' as number | '',
        grade: '',
        quantity: 1,
        unit_cost: '',
        acquired_at: '',
        source: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            catalog_item_id: d.catalog_item_id,
            collection_id: d.collection_id || null,
            new_collection_name: d.new_collection_name || null,
            quantity: Number(d.quantity) || 1,
            unit_cost: d.unit_cost ? Math.round(parseFloat(d.unit_cost) * 100) : 0,
            acquired_at: d.acquired_at || null,
            source: d.source || null,
            ...(mode === 'graded'
                ? {
                      grading_company_id: d.grading_company_id || null,
                      grade: d.grade ? Number(d.grade) : null,
                      condition: null,
                  }
                : { condition: d.condition, grading_company_id: null, grade: null }),
        }));

        form.post('/collection', {
            preserveScroll: true,
            ...postOptions,
            onSuccess: () => {
                setOpen(false);
                form.reset();
                toast.success('Added to your collection.');
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button variant="default" size="sm">
                        <Plus className="size-4" />
                        Add to collection
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Add to collection</DialogTitle>
                        <DialogDescription>
                            Record a copy you own. Value is read from market data for the
                            state you pick.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        {/* Which collection (pick existing or create new) */}
                        <ListTargetPicker
                            endpoint="/collection/targets"
                            label="collection"
                            open={open}
                            onChange={(id, newName) => {
                                form.setData('collection_id', id);
                                form.setData(
                                    'new_collection_name',
                                    newName ?? '',
                                );
                            }}
                        />

                        {/* Raw vs graded */}
                        <div className="inline-flex rounded-md border border-border p-0.5 text-sm">
                            {(['raw', 'graded'] as const).map((m) => (
                                <button
                                    key={m}
                                    type="button"
                                    onClick={() => setMode(m)}
                                    className={cn(
                                        'rounded px-3 py-1 capitalize transition-colors',
                                        mode === m
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {m === 'raw' ? 'Raw' : 'Graded'}
                                </button>
                            ))}
                        </div>

                        {mode === 'raw' ? (
                            <div className="grid gap-2">
                                <Label htmlFor="condition">Condition</Label>
                                <Select
                                    value={form.data.condition}
                                    onValueChange={(v) => form.setData('condition', v)}
                                >
                                    <SelectTrigger id="condition">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CONDITIONS.map((c) => (
                                            <SelectItem key={c.value} value={c.value}>
                                                {c.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="company">Grader</Label>
                                    <Select
                                        value={
                                            form.data.grading_company_id
                                                ? String(form.data.grading_company_id)
                                                : ''
                                        }
                                        onValueChange={(v) =>
                                            form.setData('grading_company_id', Number(v))
                                        }
                                    >
                                        <SelectTrigger id="company">
                                            <SelectValue placeholder="PSA, BGS…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {gradingCompanies.map((g) => (
                                                <SelectItem key={g.id} value={String(g.id)}>
                                                    {g.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="grade">Grade</Label>
                                    <Input
                                        id="grade"
                                        type="number"
                                        min={1}
                                        max={10}
                                        step={0.5}
                                        value={form.data.grade}
                                        onChange={(e) => form.setData('grade', e.target.value)}
                                        placeholder="10"
                                    />
                                </div>
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="quantity">Quantity</Label>
                                <Input
                                    id="quantity"
                                    type="number"
                                    min={1}
                                    value={form.data.quantity}
                                    onChange={(e) =>
                                        form.setData('quantity', Number(e.target.value))
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="unit_cost">Cost per card ($)</Label>
                                <Input
                                    id="unit_cost"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={form.data.unit_cost}
                                    onChange={(e) => form.setData('unit_cost', e.target.value)}
                                    placeholder="0.00"
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="acquired_at">Acquired</Label>
                                <Input
                                    id="acquired_at"
                                    type="date"
                                    value={form.data.acquired_at}
                                    onChange={(e) =>
                                        form.setData('acquired_at', e.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="source">Source</Label>
                                <Input
                                    id="source"
                                    value={form.data.source}
                                    onChange={(e) => form.setData('source', e.target.value)}
                                    placeholder="eBay, LGS…"
                                />
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            Add to collection
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
