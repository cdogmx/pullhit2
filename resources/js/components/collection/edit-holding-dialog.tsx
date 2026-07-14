import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { CollectionFolderPicker } from '@/components/collection/collection-folder-picker';
import type { CollectionFolderChoice } from '@/components/collection/collection-folder-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { GradingCompanyOption } from '@/types';

/** The subset of a holding the edit modal reads. */
export type EditableHolding = {
    id: number;
    collection_id: number;
    name: string;
    condition: string | null;
    grade: number | null;
    grading_company?: { id: number } | null;
    quantity: number;
    is_for_sale: boolean;
    notes: string | null;
    folder: string | null;
};

const CONDITIONS = [
    { value: 'NM', label: 'Near Mint' },
    { value: 'LP', label: 'Lightly Played' },
    { value: 'MP', label: 'Moderately Played' },
    { value: 'HP', label: 'Heavily Played' },
    { value: 'DMG', label: 'Damaged' },
];

export function EditHoldingDialog({
    holding,
    gradingCompanies,
    open,
    onOpenChange,
}: {
    holding: EditableHolding | null;
    gradingCompanies: GradingCompanyOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                {/* Keyed so the form re-seeds from props each time it opens. */}
                {holding && (
                    <EditHoldingForm
                        key={holding.id}
                        holding={holding}
                        gradingCompanies={gradingCompanies}
                        onOpenChange={onOpenChange}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditHoldingForm({
    holding,
    gradingCompanies,
    onOpenChange,
}: {
    holding: EditableHolding;
    gradingCompanies: GradingCompanyOption[];
    onOpenChange: (open: boolean) => void;
}) {
    const initialGraded = !!holding.grading_company;
    const [graded, setGraded] = useState(initialGraded);
    const [condition, setCondition] = useState(holding.condition ?? 'NM');
    const [companyId, setCompanyId] = useState(
        holding.grading_company ? String(holding.grading_company.id) : '',
    );
    const [grade, setGrade] = useState(
        holding.grade != null ? String(holding.grade) : '',
    );
    const [quantity, setQuantity] = useState(String(holding.quantity));
    const [forSale, setForSale] = useState(holding.is_for_sale);
    const [notes, setNotes] = useState(holding.notes ?? '');
    const [choice, setChoice] = useState<CollectionFolderChoice>({
        collectionId: holding.collection_id,
        newCollectionName: null,
        folder: holding.folder,
    });
    const [saving, setSaving] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const payload: Record<string, string | number | boolean | null> = {
            quantity: Math.max(0, parseInt(quantity, 10) || 0),
            is_for_sale: forSale,
            folder: choice.folder,
            notes: notes.trim() === '' ? null : notes.trim().slice(0, 1000),
        };

        if (graded) {
            payload.grading_company_id = companyId ? Number(companyId) : null;
            payload.grade = grade ? parseFloat(grade) : null;
            payload.condition = null;
        } else {
            payload.condition = condition;
            payload.grading_company_id = null;
            payload.grade = null;
        }

        // A different collection than the holding's current one → move it.
        if (
            choice.collectionId != null &&
            choice.collectionId !== holding.collection_id
        ) {
            payload.collection_id = choice.collectionId;
        }

        setSaving(true);
        router.patch(`/collection/${holding.id}`, payload, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Holding updated.');
                onOpenChange(false);
            },
            onFinish: () => setSaving(false),
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle>Edit holding</DialogTitle>
                <DialogDescription>{holding.name}</DialogDescription>
            </DialogHeader>

            <div className="grid gap-4 py-4">
                {/* State: raw vs graded */}
                <div className="grid gap-1.5">
                    <Label className="text-xs">Condition / grade</Label>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant={graded ? 'outline' : 'default'}
                            onClick={() => setGraded(false)}
                        >
                            Raw
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={graded ? 'default' : 'outline'}
                            onClick={() => setGraded(true)}
                        >
                            Graded
                        </Button>
                    </div>
                </div>

                {graded ? (
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1.5">
                            <Label className="text-xs">Grading company</Label>
                            <Select value={companyId} onValueChange={setCompanyId}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Company" />
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
                        <div className="grid gap-1.5">
                            <Label className="text-xs">Grade</Label>
                            <Input
                                type="number"
                                min={1}
                                max={10}
                                step={0.5}
                                value={grade}
                                onChange={(e) => setGrade(e.target.value)}
                                placeholder="10"
                            />
                        </div>
                    </div>
                ) : (
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Condition</Label>
                        <Select value={condition} onValueChange={setCondition}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {CONDITIONS.map((cnd) => (
                                    <SelectItem key={cnd.value} value={cnd.value}>
                                        {cnd.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}

                <div className="grid gap-1.5">
                    <Label className="text-xs">Quantity</Label>
                    <Input
                        type="number"
                        min={0}
                        value={quantity}
                        onChange={(e) => setQuantity(e.target.value)}
                    />
                </div>

                {/* Collection + folder dropdowns (move + file), each with a + to
                    create. Folders are scoped to the chosen collection. */}
                <CollectionFolderPicker
                    open={true}
                    initialCollectionId={holding.collection_id}
                    initialFolder={holding.folder}
                    allowNewCollection={false}
                    onChange={setChoice}
                />

                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={forSale}
                        onCheckedChange={(c) => setForSale(c === true)}
                    />
                    For sale
                </label>

                <div className="grid gap-1.5">
                    <Label className="text-xs">Notes</Label>
                    <Textarea
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Optional"
                        maxLength={1000}
                    />
                </div>
            </div>

            <DialogFooter>
                <Button type="submit" disabled={saving}>
                    Save changes
                </Button>
            </DialogFooter>
        </form>
    );
}
