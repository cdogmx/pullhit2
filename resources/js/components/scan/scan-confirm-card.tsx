import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney, languageLabel } from '@/lib/format';
import type { GradingCompanyOption, ScanDetected } from '@/types';

const CONDITIONS = [
    { value: 'NM', label: 'Near Mint' },
    { value: 'LP', label: 'Lightly Played' },
    { value: 'MP', label: 'Moderately Played' },
    { value: 'HP', label: 'Heavily Played' },
    { value: 'DMG', label: 'Damaged' },
    { value: 'SEALED', label: 'Sealed' },
];

/**
 * One detected card in the confirm grid: pick the right catalog match, set the
 * state (raw/graded) + quantity + cost, and add it to the collection. Adds POST
 * to the existing /collection flow while preserving the scan results on-page.
 */
export function ScanConfirmCard({
    detected,
    gradingCompanies,
}: {
    detected: ScanDetected;
    gradingCompanies: GradingCompanyOption[];
}) {
    const id = detected.identified;
    const [candidateIdx, setCandidateIdx] = useState(detected.candidates.length ? 0 : -1);
    const [mode, setMode] = useState<'raw' | 'graded'>(id.is_graded ? 'graded' : 'raw');
    const [condition, setCondition] = useState('NM');
    const [companyId, setCompanyId] = useState<number | ''>(
        gradingCompanies.find((g) => g.slug === id.grading_company)?.id ?? '',
    );
    const [grade, setGrade] = useState(id.grade != null ? String(id.grade) : '');
    const [quantity, setQuantity] = useState(1);
    const [cost, setCost] = useState('');
    const [busy, setBusy] = useState(false);
    const [added, setAdded] = useState(false);

    const chosen = candidateIdx >= 0 ? detected.candidates[candidateIdx] : null;

    /**
     * Teach the recognition cache that this scanned image is the chosen catalog
     * item, so the same-looking card is matched without an AI read next time.
     * Fire-and-forget — never blocks adding the card.
     */
    const learnFingerprint = () => {
        if (!detected.fingerprint || !chosen) {
            return;
        }

        const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        void fetch('/scan/confirm', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                fingerprint: detected.fingerprint,
                catalog_item_id: chosen.card.id,
            }),
        }).catch(() => {});
    };

    const add = () => {
        if (!chosen) {
            return;
        }

        setBusy(true);
        const payload = {
            catalog_item_id: chosen.card.id,
            quantity,
            unit_cost: cost ? Math.round(parseFloat(cost) * 100) : 0,
            ...(mode === 'graded'
                ? { grading_company_id: companyId || null, grade: grade ? Number(grade) : null }
                : { condition }),
        };
        router.post('/collection', payload, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setAdded(true);
                learnFingerprint();
                toast.success(`${chosen.card.display_name ?? chosen.card.name} added to your collection.`);
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="flex flex-col gap-3 rounded-lg border border-border p-3 sm:flex-row">
            {/* Side-by-side: the scanned photo (bulk only) and the matched card's
                reference image, so the user can confirm the match at a glance. */}
            {(detected.thumbnail || chosen?.card.image_url) && (
                <div className="flex gap-2 self-center sm:self-start">
                    {detected.thumbnail && (
                        <figure className="m-0 text-center">
                            <img
                                src={detected.thumbnail}
                                alt=""
                                className="h-32 w-auto rounded"
                            />
                            <figcaption className="mt-1 text-[10px] text-muted-foreground">
                                Your scan
                            </figcaption>
                        </figure>
                    )}
                    {chosen?.card.image_url && (
                        <figure className="m-0 text-center">
                            <img
                                src={chosen.card.image_url}
                                alt={chosen.card.display_name ?? chosen.card.name}
                                className="h-32 w-auto rounded"
                            />
                            <figcaption className="mt-1 text-[10px] text-muted-foreground">
                                Match
                            </figcaption>
                        </figure>
                    )}
                </div>
            )}

            <div className="min-w-0 flex-1 space-y-2">
                <div className="text-sm">
                    <span className="font-medium">{id.name ?? 'Unknown card'}</span>{' '}
                    <span className="text-muted-foreground">
                        {id.number}
                        {id.set_name ? ` · ${id.set_name}` : ''}
                        {id.language ? ` · ${languageLabel(id.language)}` : ''}
                    </span>
                    {detected.source === 'cache' ? (
                        <Badge
                            variant="secondary"
                            className="ml-2 text-[10px]"
                            title="Matched from a previous scan — no AI read used"
                        >
                            Recognized
                        </Badge>
                    ) : (
                        <Badge variant="outline" className="ml-2 text-[10px]">
                            {Math.round(id.confidence * 100)}% read
                        </Badge>
                    )}
                </div>

                {detected.candidates.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No catalog match found — can't add yet (the catalog may not include this
                        card).
                    </p>
                ) : (
                    <>
                        <div className="grid gap-2">
                            <Label className="text-xs">Match</Label>
                            <Select
                                value={String(candidateIdx)}
                                onValueChange={(v) => setCandidateIdx(Number(v))}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {detected.candidates.map((c, i) => {
                                        const origin = [
                                            c.card.product_line?.name,
                                            c.card.set?.name,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ');

                                        return (
                                            <SelectItem key={c.card.id} value={String(i)}>
                                                <span className="font-medium">
                                                    {c.card.display_name ?? c.card.name}
                                                </span>{' '}
                                                {c.card.number}
                                                {origin && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · {origin}
                                                    </span>
                                                )}
                                                {c.card.market_value && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        ·{' '}
                                                        {formatMoney(
                                                            c.card.market_value.median,
                                                            c.card.market_value.currency,
                                                        )}
                                                    </span>
                                                )}{' '}
                                                <span className="text-muted-foreground">
                                                    ({Math.round(c.score * 100)}%)
                                                </span>
                                            </SelectItem>
                                        );
                                    })}
                                    <SelectItem value="-1">Not in catalog</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {chosen && (
                            <>
                                <div className="inline-flex rounded-md border border-border p-0.5 text-xs">
                                    {(['raw', 'graded'] as const).map((m) => (
                                        <button
                                            key={m}
                                            type="button"
                                            onClick={() => setMode(m)}
                                            className={
                                                mode === m
                                                    ? 'rounded bg-primary px-2 py-1 text-primary-foreground'
                                                    : 'px-2 py-1 text-muted-foreground'
                                            }
                                        >
                                            {m === 'raw' ? 'Raw' : 'Graded'}
                                        </button>
                                    ))}
                                </div>

                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    {mode === 'raw' ? (
                                        <div className="grid gap-1">
                                            <Label className="text-xs">Condition</Label>
                                            <Select value={condition} onValueChange={setCondition}>
                                                <SelectTrigger>
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
                                        <>
                                            <div className="grid gap-1">
                                                <Label className="text-xs">Grader</Label>
                                                <Select
                                                    value={companyId ? String(companyId) : ''}
                                                    onValueChange={(v) => setCompanyId(Number(v))}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="PSA…" />
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
                                            <div className="grid gap-1">
                                                <Label className="text-xs">Grade</Label>
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    max={10}
                                                    step={0.5}
                                                    value={grade}
                                                    onChange={(e) => setGrade(e.target.value)}
                                                />
                                            </div>
                                        </>
                                    )}
                                    <div className="grid gap-1">
                                        <Label className="text-xs">Qty</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            value={quantity}
                                            onChange={(e) => setQuantity(Number(e.target.value))}
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label className="text-xs">Cost ($)</Label>
                                        <Input
                                            type="number"
                                            min={0}
                                            step="0.01"
                                            value={cost}
                                            onChange={(e) => setCost(e.target.value)}
                                            placeholder="0.00"
                                        />
                                    </div>
                                </div>

                                <Button
                                    size="sm"
                                    onClick={add}
                                    disabled={busy || added}
                                    variant={added ? 'outline' : 'default'}
                                >
                                    {added ? (
                                        <>
                                            <Check className="size-4" /> Added
                                        </>
                                    ) : (
                                        'Add to collection'
                                    )}
                                </Button>
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
