import { useForm } from '@inertiajs/react';
import { LibraryBig, Minus, Plus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
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

type Holding = {
    collection_id: number;
    condition: string | null;
    grading_company_id: number | null;
    grade: number | null;
    quantity: number;
};

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
    ownedQty = 0,
    postOptions,
}: {
    catalogItemId: number;
    gradingCompanies: GradingCompanyOption[];
    /** Custom trigger element; defaults to a labelled "Add to collection" button. */
    trigger?: ReactNode;
    /** Quantity the viewer already owns — flips the default trigger to "N in collection". */
    ownedQty?: number;
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
    // The viewer's existing holdings of this card, so the quantity field can
    // pre-fill with how many they already own of the selected collection + state.
    const [holdings, setHoldings] = useState<Holding[] | null>(null);
    const [defaultCollectionId, setDefaultCollectionId] = useState<number | null>(
        null,
    );

    const form = useForm({
        catalog_item_id: catalogItemId,
        collection_id: null as number | null,
        new_collection_name: '',
        condition: 'NM',
        grading_company_id: '' as number | '',
        grade: '',
        quantity: ownedQty,
        unit_cost: '',
        acquired_at: '',
        source: '',
    });

    // Load current holdings whenever the dialog opens.
    useEffect(() => {
        if (!open) {
            return;
        }

        let active = true;
        fetch(`/collection/holdings/${catalogItemId}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((d: { default_collection_id: number; holdings: Holding[] }) => {
                if (active) {
                    setHoldings(d.holdings);
                    setDefaultCollectionId(d.default_collection_id);
                }
            })
            .catch(() => active && setHoldings([]));

        return () => {
            active = false;
        };
    }, [open, catalogItemId]);

    // How many the viewer owns of the currently-selected collection + state.
    const ownedForSelection = useMemo(() => {
        if (!holdings || form.data.new_collection_name) {
            return form.data.new_collection_name ? 0 : null;
        }

        const collectionId = form.data.collection_id ?? defaultCollectionId;
        const match = holdings.find((h) => {
            if (h.collection_id !== collectionId) {
                return false;
            }

            return mode === 'graded'
                ? h.grading_company_id === Number(form.data.grading_company_id) &&
                      h.grade === Number(form.data.grade)
                : h.grading_company_id === null &&
                      h.condition === form.data.condition;
        });

        return match?.quantity ?? 0;
         
    }, [
        holdings,
        defaultCollectionId,
        mode,
        form.data.collection_id,
        form.data.new_collection_name,
        form.data.condition,
        form.data.grading_company_id,
        form.data.grade,
    ]);

    // Pre-fill the quantity with what's already owned for the current selection.
    useEffect(() => {
        if (ownedForSelection !== null) {
            form.setData('quantity', ownedForSelection);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ownedForSelection]);

    const setQty = (n: number) => form.setData('quantity', Math.max(0, n));

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            collection_id: d.collection_id || null,
            new_collection_name: d.new_collection_name || null,
            quantity: Math.max(0, Number(d.quantity) || 0),
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

        form.post(`/collection/${catalogItemId}/quantity`, {
            preserveScroll: true,
            ...postOptions,
            onSuccess: () => {
                setOpen(false);
                toast.success('Collection updated.');
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ??
                    (ownedQty > 0 ? (
                        <Button variant="secondary" size="sm">
                            <LibraryBig className="size-4" />
                            {ownedQty} in collection
                        </Button>
                    ) : (
                        <Button variant="default" size="sm">
                            <Plus className="size-4" />
                            Add to collection
                        </Button>
                    ))}
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Your collection</DialogTitle>
                        <DialogDescription>
                            Set how many copies you own of this state. Value is read
                            from market data for the state you pick.
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
                                <Label htmlFor="quantity">Quantity owned</Label>
                                <div className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        className="size-9 shrink-0"
                                        aria-label="Decrease quantity"
                                        onClick={() =>
                                            setQty(Number(form.data.quantity) - 1)
                                        }
                                    >
                                        <Minus className="size-4" />
                                    </Button>
                                    <Input
                                        id="quantity"
                                        type="number"
                                        min={0}
                                        value={form.data.quantity}
                                        onChange={(e) =>
                                            setQty(Number(e.target.value))
                                        }
                                        className="text-center"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        className="size-9 shrink-0"
                                        aria-label="Increase quantity"
                                        onClick={() =>
                                            setQty(Number(form.data.quantity) + 1)
                                        }
                                    >
                                        <Plus className="size-4" />
                                    </Button>
                                </div>
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
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
