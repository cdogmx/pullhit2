import { Head, Link } from '@inertiajs/react';
import { Camera, History, ImagePlus, Loader2, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import type { CollectionTargets } from '@/components/collection/collection-folder-picker';
import { LiveScanner } from '@/components/scan/live-scanner';
import { ScanConfirmCard } from '@/components/scan/scan-confirm-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/format';
import { captureNativePhoto, isNativeApp } from '@/lib/native-camera';
import { cn } from '@/lib/utils';
import type {
    CatalogItem,
    GradingCompanyOption,
    ScanDetected,
    ScanResult,
    ScanUsage,
} from '@/types';

type Props = {
    usage: ScanUsage;
    gradingCompanies: GradingCompanyOption[];
    /** The user's existing collection folders, for the add-to-folder picker. */
    folders: string[];
};

const MAX_PX = 1568;

// Persist the most recent scan so an accidental navigation away doesn't lose it.
const STORAGE_KEY = 'cardfoo:last-scan';
const STORAGE_TTL_MS = 24 * 60 * 60 * 1000;

// Rotating progress copy shown while the AI read is in flight (the request is a
// single round-trip, so these are illustrative of the real pipeline, not live).
const STEP_COPY: Record<'single' | 'bulk', string[]> = {
    single: [
        'Framing the card…',
        'Checking the cache…',
        'Reading with AI…',
        'Matching to the catalog…',
    ],
    bulk: [
        'Detecting cards…',
        'Checking the cache…',
        'Reading with AI…',
        'Matching to the catalog…',
    ],
};

function readStoredScan(): ScanDetected[] | null {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw) as { at: number; detected: ScanDetected[] };

        if (!parsed?.detected?.length || Date.now() - parsed.at > STORAGE_TTL_MS) {
            return null;
        }

        return parsed.detected;
    } catch {
        return null;
    }
}

function storeScan(detected: ScanDetected[] | null): void {
    try {
        if (detected && detected.length > 0) {
            localStorage.setItem(
                STORAGE_KEY,
                JSON.stringify({ at: Date.now(), detected }),
            );
        } else {
            localStorage.removeItem(STORAGE_KEY);
        }
    } catch {
        // Ignore storage failures (private mode, quota, etc.).
    }
}

/** Read the XSRF-TOKEN cookie Laravel set, for the POST header. */
function xsrfToken(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

/** Downscale an image file to ≤MAX_PX on its long edge and return JPEG base64. */
function downscale(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
            const scale = Math.min(1, MAX_PX / Math.max(img.width, img.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.width * scale);
            canvas.height = Math.round(img.height * scale);
            const ctx = canvas.getContext('2d');

            if (!ctx) {
                reject(new Error('Canvas unavailable'));

                return;
            }

            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            resolve(canvas.toDataURL('image/jpeg', 0.85).split(',')[1]);
        };
        img.onerror = () => reject(new Error('Could not read image'));
        img.src = URL.createObjectURL(file);
    });
}

export default function ScanIndex({
    usage: initialUsage,
    gradingCompanies,
}: Props) {
    // A restored scan opens in the confirm flow; a fresh session leads with live.
    const [mode, setMode] = useState<'live' | 'single' | 'bulk'>(() =>
        readStoredScan() ? 'single' : 'live',
    );
    // Live-scan sub-phase: capturing cards vs reviewing the stack before add.
    const [phase, setPhase] = useState<'capture' | 'review'>('capture');
    const [busy, setBusy] = useState(false);
    const [step, setStep] = useState(0);
    const [usage, setUsage] = useState(initialUsage);
    const [detected, setDetected] = useState<ScanDetected[] | null>(
        readStoredScan,
    );
    // Collections + folders for the per-card add picker — loaded once and shared
    // across every confirm card so it never fetches per card.
    const [targets, setTargets] = useState<CollectionTargets | null>(null);
    useEffect(() => {
        let active = true;
        fetch('/collection/targets', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((d: CollectionTargets) => active && setTargets(d))
            .catch(() => {});

        return () => {
            active = false;
        };
    }, []);

    // Whether the shown results were restored from a previous session.
    const [fromStorage, setFromStorage] = useState<boolean>(
        () => readStoredScan() !== null,
    );
    // The photo the user just scanned (data URL), shown alongside each match.
    const [photo, setPhoto] = useState<string | null>(null);
    // The catalog item currently selected for each detected card (by index), so
    // we can total the scan's value and render the scanned-cards scroller.
    const [chosenCards, setChosenCards] = useState<(CatalogItem | null)[]>([]);
    // A quick "percent of value" calculator (buylist/trade offers) applied to
    // the scan total — blank means 100%.
    const [pct, setPct] = useState('');
    const fileRef = useRef<HTMLInputElement>(null);
    const libraryRef = useRef<HTMLInputElement>(null);

    const handleChosen = useCallback(
        (index: number, card: CatalogItem | null) =>
            setChosenCards((prev) => {
                if (prev[index] === card) {
                    return prev;
                }

                const next = prev.slice();
                next[index] = card;

                return next;
            }),
        [],
    );

    // The scan's total value (sum of each chosen card's headline median) and how
    // many of the detected cards have a price.
    const { total, pricedCount } = useMemo(() => {
        let total = 0;
        let pricedCount = 0;

        for (const card of chosenCards) {
            const median = card?.market_value?.median;

            if (median != null) {
                total += median;
                pricedCount += 1;
            }
        }

        return { total, pricedCount };
    }, [chosenCards]);

    // Blank → 100%; a bad entry also falls back to 100% so the figure is never NaN.
    const parsedPct = Number(pct);
    const pctNum =
        pct.trim() === '' || !Number.isFinite(parsedPct) ? 100 : parsedPct;

    const steps = STEP_COPY[mode === 'bulk' ? 'bulk' : 'single'];

    // Rotate the progress copy while a scan is in flight.
    useEffect(() => {
        if (!busy) {
            return;
        }

        const id = setInterval(
            () => setStep((s) => (s + 1) % steps.length),
            1800,
        );

        return () => clearInterval(id);
    }, [busy, steps.length]);

    const clearScan = () => {
        setDetected(null);
        setFromStorage(false);
        setPhoto(null);
        setChosenCards([]);
        setPhase('capture');
        storeScan(null);
    };

    const changeMode = (m: 'live' | 'single' | 'bulk') => {
        setMode(m);
        setPhase('capture');

        if (m === 'live') {
            clearScan();
        }
    };

    const scrollToCard = (i: number) =>
        document
            .getElementById(`scan-card-${i}`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });

    // Send a base64 JPEG (web downscale, live-camera frame, or native camera) to
    // /scan. `append` (live scanning) stacks the read onto the running list and
    // auto-picks the best match; otherwise it replaces the current scan.
    const submitImage = async (image: string, append = false) => {
        setStep(0);
        setBusy(true);
        setFromStorage(false);
        setPhoto(`data:image/jpeg;base64,${image}`);

        if (!append) {
            setDetected(null);
            setChosenCards([]);
        }

        try {
            const res = await fetch('/scan', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    image,
                    media_type: 'image/jpeg',
                    // Live capture reads one card at a time.
                    mode: append ? 'single' : mode === 'bulk' ? 'bulk' : 'single',
                }),
            });

            if (res.status === 429) {
                const body = await res.json();
                setUsage(body.usage);
                toast.error(body.message ?? 'Monthly scan limit reached.');

                return;
            }

            if (!res.ok) {
                toast.error('Scan failed. Please try again.');

                return;
            }

            const result: ScanResult = await res.json();
            setUsage(result.usage);

            if (append) {
                const entry = result.detected[0];

                if (!entry) {
                    toast.message('No card detected — line it up and try again.');

                    return;
                }

                // Stack it, pre-choosing the top candidate so the strip + total
                // fill in immediately (the review step lets them adjust).
                setDetected((prev) => {
                    const next = [...(prev ?? []), entry];
                    storeScan(next);

                    return next;
                });
                setChosenCards((prev) => [
                    ...prev,
                    entry.candidates[0]?.card ?? null,
                ]);
            } else {
                setDetected(result.detected);
                storeScan(result.detected);

                if (result.detected.length === 0) {
                    toast.message('No cards detected in that photo.');
                }
            }
        } catch {
            toast.error('Could not process that image.');
        } finally {
            setBusy(false);

            // Reset both inputs so re-picking the same file fires onChange again.
            if (fileRef.current) {
                fileRef.current.value = '';
            }

            if (libraryRef.current) {
                libraryRef.current.value = '';
            }
        }
    };

    // In live capture, uploads from the library stack too.
    const appendMode = mode === 'live' && phase === 'capture';

    const onFile = async (file: File) => {
        try {
            await submitImage(await downscale(file), appendMode);
        } catch {
            toast.error('Could not process that image.');
        }
    };

    // Native shell: use the device camera directly (better than a web <input>).
    const captureNative = async () => {
        const image = await captureNativePhoto();

        if (image) {
            await submitImage(image);
        }
    };

    return (
        <>
            <Head title="Scan" />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="inline-flex rounded-md border border-border p-0.5 text-sm">
                        {(['live', 'single', 'bulk'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                onClick={() => changeMode(m)}
                                className={cn(
                                    'rounded px-3 py-1 capitalize transition-colors',
                                    mode === m
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {m === 'live'
                                    ? 'Live scan'
                                    : m === 'single'
                                      ? 'Single card'
                                      : 'Bulk (page)'}
                            </button>
                        ))}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {usage.unlimited
                            ? 'Unlimited scans'
                            : `${usage.used} / ${usage.cap} scans this month`}
                        {!usage.unlimited && (usage.credits ?? 0) > 0 && (
                            <span> · {usage.credits} credits</span>
                        )}
                    </p>
                </div>

                <Link
                    href="/scan/history"
                    className="-mt-2 inline-flex w-fit items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                >
                    <History className="size-4" />
                    Scan history
                </Link>

                {/* Hidden pickers — available to both the live scanner (gallery)
                    and the upload flow. */}
                <input
                    ref={fileRef}
                    type="file"
                    accept="image/*"
                    capture="environment"
                    className="hidden"
                    onChange={(e) => {
                        const f = e.target.files?.[0];

                        if (f) {
                            void onFile(f);
                        }
                    }}
                />
                <input
                    ref={libraryRef}
                    type="file"
                    accept="image/*"
                    className="hidden"
                    onChange={(e) => {
                        const f = e.target.files?.[0];

                        if (f) {
                            void onFile(f);
                        }
                    }}
                />

                {/* Live continuous scanner (Collectr-style). */}
                {mode === 'live' && phase === 'capture' && (
                    <LiveScanner
                        scanned={detected ?? []}
                        chosenCards={chosenCards}
                        total={total}
                        busy={busy}
                        onShutter={(img) => void submitImage(img, true)}
                        onGallery={() => libraryRef.current?.click()}
                        onNext={() => setPhase('review')}
                        onExit={() => changeMode('single')}
                    />
                )}

                {/* Upload flow (single card / bulk page). */}
                {mode !== 'live' && (
                    <Card>
                        <CardContent className="pt-6">
                            <button
                                type="button"
                                onClick={() =>
                                    isNativeApp()
                                        ? captureNative()
                                        : fileRef.current?.click()
                                }
                                disabled={busy}
                                className="flex w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border py-12 text-muted-foreground transition-colors hover:bg-accent/40 disabled:opacity-60"
                            >
                                {busy ? (
                                    <Loader2 className="size-8 animate-spin" />
                                ) : (
                                    <Camera className="size-8" />
                                )}
                                <span className="text-sm font-medium">
                                    {busy
                                        ? steps[step]
                                        : mode === 'single'
                                          ? 'Take or upload a photo of one card'
                                          : 'Take or upload a photo of a binder page'}
                                </span>
                                {busy && (
                                    <span className="flex gap-1">
                                        {steps.map((_, i) => (
                                            <span
                                                key={i}
                                                className={cn(
                                                    'size-1.5 rounded-full transition-colors',
                                                    i === step
                                                        ? 'bg-primary'
                                                        : 'bg-muted-foreground/30',
                                                )}
                                            />
                                        ))}
                                    </span>
                                )}
                            </button>

                            {!busy && (
                                <button
                                    type="button"
                                    onClick={() => libraryRef.current?.click()}
                                    className="mt-3 flex w-full items-center justify-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    <ImagePlus className="size-4" />
                                    Choose from library
                                </button>
                            )}
                        </CardContent>
                    </Card>
                )}

                {detected &&
                    detected.length > 0 &&
                    (mode !== 'live' || phase === 'review') && (
                    <div className="space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-muted-foreground">
                                {fromStorage
                                    ? 'Your recent scan'
                                    : `${detected.length} ${detected.length === 1 ? 'card' : 'cards'} scanned — confirm and add`}
                            </h2>
                            <div className="flex items-center gap-1">
                                {mode === 'live' && phase === 'review' && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setPhase('capture')}
                                    >
                                        <Camera className="size-4" />
                                        Scan more
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={clearScan}
                                >
                                    <X className="size-4" />
                                    Clear
                                </Button>
                            </div>
                        </div>
                        {fromStorage && (
                            <p className="text-xs text-muted-foreground">
                                Picked up where you left off. Confirm and add, or
                                clear to start a new scan.
                            </p>
                        )}

                        {/* Scanned-value summary + a horizontal strip of every
                            detected card; tap a tile to jump to its row. */}
                        {total > 0 && (
                            <Card>
                                <CardContent className="space-y-3 py-4">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <span className="text-sm text-muted-foreground">
                                            Scanned value
                                        </span>
                                        <span className="text-xl font-bold tracking-tight tabular-nums">
                                            {formatMoney(total)}
                                        </span>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {pricedCount} of {detected.length}{' '}
                                        {detected.length === 1 ? 'card' : 'cards'}{' '}
                                        priced · headline near-mint value
                                    </p>

                                    {/* Quick % of value — for buylist / trade
                                        offers (e.g. "I'll pay 70%"). */}
                                    <div className="flex items-center gap-2 border-t border-border/60 pt-3">
                                        <label
                                            htmlFor="scan-pct"
                                            className="text-sm text-muted-foreground"
                                        >
                                            % of value
                                        </label>
                                        <div className="relative">
                                            <Input
                                                id="scan-pct"
                                                type="number"
                                                min={0}
                                                max={1000}
                                                inputMode="decimal"
                                                value={pct}
                                                onChange={(e) =>
                                                    setPct(e.target.value)
                                                }
                                                placeholder="100"
                                                className="h-8 w-20 pr-6 tabular-nums"
                                            />
                                            <span className="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 text-sm text-muted-foreground">
                                                %
                                            </span>
                                        </div>
                                        <span className="ml-auto text-lg font-bold tracking-tight tabular-nums">
                                            {formatMoney(
                                                Math.round((total * pctNum) / 100),
                                            )}
                                        </span>
                                    </div>

                                    {detected.length > 1 && (
                                        <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                                            {detected.map((d, i) => {
                                                const card = chosenCards[i] ?? null;
                                                const img =
                                                    card?.image_url ??
                                                    d.thumbnail ??
                                                    photo;
                                                const median =
                                                    card?.market_value?.median;

                                                return (
                                                    <button
                                                        key={i}
                                                        type="button"
                                                        onClick={() =>
                                                            scrollToCard(i)
                                                        }
                                                        className="flex w-24 shrink-0 flex-col items-center gap-1 rounded-md border border-border bg-card p-1.5 text-center transition-colors hover:border-primary"
                                                        title={
                                                            card?.display_name ??
                                                            card?.name ??
                                                            d.identified.name ??
                                                            'Unknown card'
                                                        }
                                                    >
                                                        {img ? (
                                                            <img
                                                                src={img}
                                                                alt=""
                                                                className="h-20 w-auto rounded"
                                                            />
                                                        ) : (
                                                            <div className="flex h-20 w-14 items-center justify-center rounded bg-muted text-[10px] text-muted-foreground">
                                                                ?
                                                            </div>
                                                        )}
                                                        <span className="w-full truncate text-[10px] font-medium">
                                                            {card?.display_name ??
                                                                card?.name ??
                                                                d.identified
                                                                    .name ??
                                                                'Unknown'}
                                                        </span>
                                                        <span className="text-[10px] tabular-nums text-muted-foreground">
                                                            {median != null
                                                                ? formatMoney(
                                                                      median,
                                                                  )
                                                                : '—'}
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {detected.map((d, i) => (
                            <div key={i} id={`scan-card-${i}`} className="scroll-mt-4">
                                <ScanConfirmCard
                                    detected={d}
                                    index={i}
                                    onChosenChange={handleChosen}
                                    scanPhoto={photo}
                                    gradingCompanies={gradingCompanies}
                                    targets={targets}
                                />
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

ScanIndex.layout = {
    breadcrumbs: [{ title: 'Scan', href: '/scan' }],
};
