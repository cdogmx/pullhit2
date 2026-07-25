import { useForm } from '@inertiajs/react';
import { LibraryBig, Minus, Plus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { CollectionFolderPicker } from '@/components/collection/collection-folder-picker';
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
 * "Add to collection" — opened from the catalog. Adds copies (delta) of a
 * priced state, matching the scan flow: quantity defaults to 1, and we show how
 * many you already own of the selected collection + state so totals are clear.
 * Money is entered in dollars and converted to cents on submit.
 *
 * Pass several ids (a browse multi-select) and it becomes a batch add: the same
 * state, quantity, and cost applied to every card via /collection/bulk. The
 * "you already own N" hint is dropped there — it's per-card, and a batch has no
 * single answer.
 */
export function AddToCollectionDialog({
    catalogItemIds,
    gradingCompanies,
    trigger,
    ownedQty = 0,
    open: controlledOpen,
    onOpenChange,
    onAdded,
    postOptions,
}: {
    /** The cards to add. One id is the normal per-card dialog; many is a batch. */
    catalogItemIds: number[];
    gradingCompanies: GradingCompanyOption[];
    /** Custom trigger element; defaults to a labelled "Add to collection" button. */
    trigger?: ReactNode;
    /** Quantity the viewer already owns (all states) — flips the default trigger label. */
    ownedQty?: number;
    /** Drive the dialog from outside (a bulk action bar) instead of a trigger. */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    /** Fired after a successful add — e.g. to clear a selection. */
    onAdded?: () => void;
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
    const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
    const open = controlledOpen ?? uncontrolledOpen;
    const setOpen = onOpenChange ?? setUncontrolledOpen;

    const cardCount = catalogItemIds.length;
    const bulk = cardCount > 1;
    const singleId = catalogItemIds[0] ?? 0;

    const [mode, setMode] = useState<'raw' | 'graded'>('raw');
    // Per collection + state holdings so we can show "you already own N".
    const [holdings, setHoldings] = useState<Holding[] | null>(null);
    const [defaultCollectionId, setDefaultCollectionId] = useState<
        number | null
    >(null);

    const form = useForm({
        collection_id: null as number | null,
        new_collection_name: '',
        condition: 'NM',
        grading_company_id: '' as number | '',
        grade: '',
        quantity: 1,
        unit_cost: '',
        acquired_at: '',
        source: '',
        folder: '',
    });

    // Load current holdings whenever the dialog opens; reset add-qty to 1.
    // A batch has no single "you already own N", so it skips the fetch.
    useEffect(() => {
        if (!open) {
            return;
        }

        form.setData('quantity', 1);

        if (bulk) {
            return;
        }

        let active = true;
        fetch(`/collection/holdings/${singleId}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then(
                (d: { default_collection_id: number; holdings: Holding[] }) => {
                    if (active) {
                        setHoldings(d.holdings);
                        setDefaultCollectionId(d.default_collection_id);
                    }
                },
            )
            .catch(() => active && setHoldings([]));

        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, singleId, bulk]);

    // How many the viewer already owns of the currently-selected collection + state.
    const ownedForSelection = useMemo(() => {
        if (bulk) {
            return null;
        }

        if (!holdings || form.data.new_collection_name) {
            return form.data.new_collection_name ? 0 : null;
        }

        const collectionId = form.data.collection_id ?? defaultCollectionId;
        const match = holdings.find((h) => {
            if (h.collection_id !== collectionId) {
                return false;
            }

            return mode === 'graded'
                ? h.grading_company_id ===
                      Number(form.data.grading_company_id) &&
                      h.grade === Number(form.data.grade)
                : h.grading_company_id === null &&
                      h.condition === form.data.condition;
        });

        return match?.quantity ?? 0;
    }, [
        bulk,
        holdings,
        defaultCollectionId,
        mode,
        form.data.collection_id,
        form.data.new_collection_name,
        form.data.condition,
        form.data.grading_company_id,
        form.data.grade,
    ]);

    const addQty = Math.max(1, Number(form.data.quantity) || 1);
    const newTotal =
        ownedForSelection != null ? ownedForSelection + addQty : null;

    const setQty = (n: number) => form.setData('quantity', Math.max(1, n));

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...(bulk
                ? { catalog_item_ids: catalogItemIds }
                : { catalog_item_id: singleId }),
            collection_id: d.collection_id || null,
            new_collection_name: d.new_collection_name || null,
            // Add-delta (same as scan) — never set absolute totals here.
            quantity: Math.max(1, Number(d.quantity) || 1),
            unit_cost: d.unit_cost
                ? Math.round(parseFloat(String(d.unit_cost)) * 100)
                : 0,
            acquired_at: d.acquired_at || null,
            source: d.source || null,
            folder: d.folder.trim() || null,
            ...(mode === 'graded'
                ? {
                      grading_company_id: d.grading_company_id || null,
                      grade: d.grade ? Number(d.grade) : null,
                      condition: null,
                  }
                : {
                      condition: d.condition,
                      grading_company_id: null,
                      grade: null,
                  }),
        }));

        form.post(bulk ? '/collection/bulk' : '/collection', {
            preserveScroll: true,
            ...postOptions,
            onSuccess: () => {
                setOpen(false);
                onAdded?.();

                if (bulk) {
                    toast.success(
                        addQty === 1
                            ? `Added ${cardCount} cards to your collection.`
                            : `Added ${cardCount} cards × ${addQty} to your collection.`,
                    );

                    return;
                }

                const totalMsg =
                    newTotal != null ? ` You now own ${newTotal}.` : '';
                toast.success(
                    addQty === 1
                        ? `Added to your collection.${totalMsg}`
                        : `Added ${addQty} to your collection.${totalMsg}`,
                );
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {/* Externally driven (a bulk action bar) — no trigger of its own. */}
            {controlledOpen === undefined && (
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
            )}
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>
                            {bulk
                                ? `Add ${cardCount} cards to collection`
                                : 'Add to collection'}
                        </DialogTitle>
                        <DialogDescription>
                            {bulk
                                ? 'The state, quantity, and cost below apply to every selected card.'
                                : 'Choose the state and how many copies to add. Value is read from market data for the state you pick.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        <CollectionFolderPicker
                            open={open}
                            onChange={(choice) => {
                                form.setData(
                                    'collection_id',
                                    choice.collectionId,
                                );
                                form.setData(
                                    'new_collection_name',
                                    choice.newCollectionName ?? '',
                                );
                                form.setData('folder', choice.folder ?? '');
                            }}
                        />

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
                                    onValueChange={(v) =>
                                        form.setData('condition', v)
                                    }
                                >
                                    <SelectTrigger id="condition">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CONDITIONS.map((c) => (
                                            <SelectItem
                                                key={c.value}
                                                value={c.value}
                                            >
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
                                                ? String(
                                                      form.data
                                                          .grading_company_id,
                                                  )
                                                : ''
                                        }
                                        onValueChange={(v) =>
                                            form.setData(
                                                'grading_company_id',
                                                Number(v),
                                            )
                                        }
                                    >
                                        <SelectTrigger id="company">
                                            <SelectValue placeholder="PSA, BGS…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {gradingCompanies.map((g) => (
                                                <SelectItem
                                                    key={g.id}
                                                    value={String(g.id)}
                                                >
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
                                        onChange={(e) =>
                                            form.setData(
                                                'grade',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="10"
                                    />
                                </div>
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="quantity">
                                    {bulk
                                        ? 'Quantity of each'
                                        : 'Quantity to add'}
                                </Label>
                                <div className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        className="size-9 shrink-0"
                                        aria-label="Decrease quantity"
                                        onClick={() =>
                                            setQty(
                                                Number(form.data.quantity) - 1,
                                            )
                                        }
                                    >
                                        <Minus className="size-4" />
                                    </Button>
                                    <Input
                                        id="quantity"
                                        type="number"
                                        min={1}
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
                                            setQty(
                                                Number(form.data.quantity) + 1,
                                            )
                                        }
                                    >
                                        <Plus className="size-4" />
                                    </Button>
                                </div>
                                {ownedForSelection != null &&
                                    ownedForSelection > 0 && (
                                        <p className="text-xs text-muted-foreground">
                                            You already own {ownedForSelection}{' '}
                                            of this state
                                            {newTotal != null && (
                                                <>
                                                    {' '}
                                                    · will be{' '}
                                                    <span className="font-medium text-foreground">
                                                        {newTotal}
                                                    </span>
                                                </>
                                            )}
                                        </p>
                                    )}
                                {ownedForSelection === 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        You don&apos;t own this state yet
                                    </p>
                                )}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="unit_cost">
                                    Cost per card ($)
                                </Label>
                                <Input
                                    id="unit_cost"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={form.data.unit_cost}
                                    onChange={(e) =>
                                        form.setData(
                                            'unit_cost',
                                            e.target.value,
                                        )
                                    }
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
                                        form.setData(
                                            'acquired_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="source">Source</Label>
                                <Input
                                    id="source"
                                    value={form.data.source}
                                    onChange={(e) =>
                                        form.setData('source', e.target.value)
                                    }
                                    placeholder="eBay, LGS…"
                                />
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            {bulk
                                ? `Add ${cardCount} cards`
                                : addQty === 1
                                  ? 'Add to collection'
                                  : `Add ${addQty} to collection`}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
