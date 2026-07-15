import { router } from '@inertiajs/react';
import { Check, Search, ThumbsDown, ThumbsUp, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { CollectionFolderPicker } from '@/components/collection/collection-folder-picker';
import type {
    CollectionFolderChoice,
    CollectionTargets,
} from '@/components/collection/collection-folder-picker';
import { CardPickTile } from '@/components/scan/card-pick-tile';
import { CatalogSearchSelect } from '@/components/scan/catalog-search-select';
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
import type { CatalogItem, GradingCompanyOption, ScanDetected } from '@/types';

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
    scanPhoto = null,
    index = 0,
    onChosenChange,
    targets = null,
}: {
    detected: ScanDetected;
    gradingCompanies: GradingCompanyOption[];
    /** The whole scanned photo — shown when there's no per-card crop (single mode). */
    scanPhoto?: string | null;
    /** This card's position in the scan, reported back with the chosen match. */
    index?: number;
    /** Notifies the parent which catalog item is currently selected (for the
     *  scanned-value total + scroller), or null when nothing matches. */
    onChosenChange?: (index: number, card: CatalogItem | null) => void;
    /** The user's collections + folders, loaded once by the scan page and shared
     *  across every card so the picker doesn't fetch per card. */
    targets?: CollectionTargets | null;
}) {
    const id = detected.identified;
    // The per-card crop (bulk) or the whole scanned photo (single) to show.
    const scanImage = detected.thumbnail ?? scanPhoto;
    const [candidateIdx, setCandidateIdx] = useState(detected.candidates.length ? 0 : -1);
    const [mode, setMode] = useState<'raw' | 'graded'>(id.is_graded ? 'graded' : 'raw');
    const [condition, setCondition] = useState('NM');
    const [companyId, setCompanyId] = useState<number | ''>(
        gradingCompanies.find((g) => g.slug === id.grading_company)?.id ?? '',
    );
    const [grade, setGrade] = useState(id.grade != null ? String(id.grade) : '');
    const [quantity, setQuantity] = useState(1);
    const [cost, setCost] = useState('');
    const [choice, setChoice] = useState<CollectionFolderChoice>({
        collectionId: null,
        newCollectionName: null,
        folder: null,
    });
    const [busy, setBusy] = useState(false);
    const [added, setAdded] = useState(false);
    // How many of the chosen card the user already owns (keyed by card id so a
    // stale fetch for a previously-selected card never shows against another).
    const [owned, setOwned] = useState<{ id: number; qty: number } | null>(null);
    // A card the user picked via catalog search when the scan got it wrong.
    const [manualCard, setManualCard] = useState<CatalogItem | null>(null);
    const [searchOpen, setSearchOpen] = useState(false);
    // "Was this right?" — null until the user answers.
    const [feedback, setFeedback] = useState<'correct' | 'wrong' | null>(null);

    const chosen = manualCard
        ? { card: manualCard, score: 1, reasons: ['manual'] }
        : candidateIdx >= 0
          ? detected.candidates[candidateIdx]
          : null;

    // Report the currently-selected card up so the page can total the scan's
    // value and render the scanned-cards scroller. Fires on mount and whenever
    // the selection changes (candidate pick, manual search, or clear).
    const chosenCard = chosen?.card ?? null;

    useEffect(() => {
        onChosenChange?.(index, chosenCard);
    }, [index, chosenCard, onChosenChange]);

    // Detect how many of this card the user already owns, so a re-scan flags a
    // duplicate. Re-fetches whenever the chosen card changes.
    useEffect(() => {
        if (!chosenCard) {
            return;
        }

        const id = chosenCard.id;
        let active = true;
        fetch(`/collection/owned/${id}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d: { quantity?: number }) => {
                if (active && typeof d.quantity === 'number') {
                    setOwned({ id, qty: d.quantity });
                }
            })
            .catch(() => {});

        return () => {
            active = false;
        };
    }, [chosenCard]);

    const value = chosenCard?.market_value ?? null;
    const ownedQty =
        owned && chosenCard && owned.id === chosenCard.id ? owned.qty : null;

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

    /**
     * Record whether the auto-suggested top match was right. On "wrong" the
     * server self-heals the cache; if the user has already picked a different
     * card, send it as the correction so the cache learns it.
     */
    const sendFeedback = (wasCorrect: boolean) => {
        const detectedId = detected.candidates[0]?.card.id ?? null;
        const correctedId =
            !wasCorrect && chosen && chosen.card.id !== detectedId
                ? chosen.card.id
                : null;

        setFeedback(wasCorrect ? 'correct' : 'wrong');

        const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        void fetch('/scan/feedback', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                source: detected.source,
                was_correct: wasCorrect,
                phash: detected.fingerprint,
                identified: id,
                detected_catalog_item_id: detectedId,
                corrected_catalog_item_id: correctedId,
            }),
        }).catch(() => {});

        toast.success(
            wasCorrect ? 'Thanks for confirming!' : 'Thanks — we’ll fix it.',
        );
    };

    const add = () => {
        if (!chosen) {
            return;
        }

        setBusy(true);
        const cardId = chosen.card.id;
        const payload = {
            catalog_item_id: cardId,
            quantity,
            unit_cost: cost ? Math.round(parseFloat(cost) * 100) : 0,
            // Which collection + folder (dropdowns, each with a + to create new).
            collection_id: choice.newCollectionName ? null : choice.collectionId,
            new_collection_name: choice.newCollectionName,
            folder: choice.folder,
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
                // Reflect the new copies in the "already owned" count immediately.
                setOwned((o) => ({
                    id: cardId,
                    qty: (o?.id === cardId ? o.qty : 0) + quantity,
                }));
                toast.success(`${chosen.card.display_name ?? chosen.card.name} added to your collection.`);
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="flex flex-col gap-3 rounded-lg border border-border p-3 sm:flex-row">
            {/* Side-by-side: the scanned photo (the per-card crop in bulk, or the
                whole uploaded photo in single) and the matched card's reference
                image, so the user can confirm the match at a glance. */}
            {scanImage && (
                <div className="flex gap-2 self-center sm:self-start">
                    {scanImage && (
                        <figure className="m-0 text-center">
                            <img
                                src={scanImage}
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
                            title="Matched from a previous confirmed scan — no AI read used"
                        >
                            Via cache
                        </Badge>
                    ) : (
                        <Badge
                            variant="outline"
                            className="ml-2 text-[10px]"
                            title="Read by AI"
                        >
                            Via AI · {Math.round(id.confidence * 100)}%
                        </Badge>
                    )}
                </div>

                {/* The chosen match's headline value, so the user sees what the
                    card is worth before adding it. */}
                {chosenCard && (
                    <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                        <span className="font-semibold tabular-nums">
                            {value ? formatMoney(value.median, value.currency) : 'No price yet'}
                        </span>
                        {value && (
                            <span className="text-xs text-muted-foreground">
                                {value.label}
                                {value.is_estimated ? ' · est.' : ''}
                            </span>
                        )}
                        {ownedQty != null && ownedQty > 0 && (
                            <Badge
                                variant="secondary"
                                className="gap-1 text-[10px]"
                                title="You already have this card in a collection"
                            >
                                <Check className="size-3" />
                                In your collection · ×{ownedQty}
                            </Badge>
                        )}
                    </div>
                )}

                {/* Detection-quality feedback — teaches the cache + flags AI misses. */}
                {detected.candidates.length > 0 && (
                    <div className="flex items-center gap-2 text-xs">
                        {feedback ? (
                            <span className="text-muted-foreground">
                                {feedback === 'correct'
                                    ? '✓ Marked correct'
                                    : '✗ Marked wrong — pick the right card below'}
                            </span>
                        ) : (
                            <>
                                <span className="text-muted-foreground">
                                    Detection correct?
                                </span>
                                <button
                                    type="button"
                                    onClick={() => sendFeedback(true)}
                                    className="inline-flex items-center gap-1 rounded-md border border-border px-2 py-0.5 text-muted-foreground transition-colors hover:border-emerald-500/40 hover:text-emerald-600 dark:hover:text-emerald-400"
                                >
                                    <ThumbsUp className="size-3.5" /> Yes
                                </button>
                                <button
                                    type="button"
                                    onClick={() => sendFeedback(false)}
                                    className="inline-flex items-center gap-1 rounded-md border border-border px-2 py-0.5 text-muted-foreground transition-colors hover:border-red-500/40 hover:text-red-600 dark:hover:text-red-400"
                                >
                                    <ThumbsDown className="size-3.5" /> No
                                </button>
                            </>
                        )}
                    </div>
                )}

                {manualCard ? (
                    <div className="flex items-center justify-between gap-2 rounded-md border border-border bg-accent/30 px-2 py-1.5 text-sm">
                        <span className="min-w-0">
                            <span className="font-medium">
                                {manualCard.display_name ?? manualCard.name}
                            </span>{' '}
                            <span className="text-muted-foreground">
                                {manualCard.number}
                                {manualCard.set?.name
                                    ? ` · ${manualCard.set.name}`
                                    : ''}
                            </span>
                        </span>
                        <button
                            type="button"
                            onClick={() => setManualCard(null)}
                            className="shrink-0 text-muted-foreground hover:text-foreground"
                            title="Clear selection"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                ) : detected.candidates.length === 0 ? (
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            No catalog match found — search for the right card:
                        </p>
                        <CatalogSearchSelect onSelect={setManualCard} />
                    </div>
                ) : (
                    <>
                        <div className="space-y-1.5">
                            <Label className="text-xs">
                                Match{' '}
                                <span className="font-normal text-muted-foreground">
                                    — pick the right card
                                </span>
                            </Label>
                            <div className="flex flex-wrap gap-2">
                                {detected.candidates.map((c, i) => (
                                    <CardPickTile
                                        key={c.card.id}
                                        card={c.card}
                                        score={c.score}
                                        selected={candidateIdx === i}
                                        onSelect={() => setCandidateIdx(i)}
                                    />
                                ))}
                            </div>
                        </div>

                        {searchOpen ? (
                            <CatalogSearchSelect
                                onSelect={(c) => {
                                    setManualCard(c);
                                    setSearchOpen(false);
                                }}
                            />
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="w-fit"
                                onClick={() => setSearchOpen(true)}
                            >
                                <Search className="size-3.5" /> Search catalog
                            </Button>
                        )}
                    </>
                )}

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

                                {/* Collection + folder dropdowns, each with a + to
                                    create. Folder is scoped to the collection. */}
                                <CollectionFolderPicker
                                    open={true}
                                    preloaded={targets ?? undefined}
                                    onChange={setChoice}
                                />

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
            </div>
        </div>
    );
}
