import { Head, Link } from '@inertiajs/react';
import { Camera, History, ImagePlus, Loader2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ScanConfirmCard } from '@/components/scan/scan-confirm-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type {
    GradingCompanyOption,
    ScanDetected,
    ScanResult,
    ScanUsage,
} from '@/types';

type Props = { usage: ScanUsage; gradingCompanies: GradingCompanyOption[] };

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
    const [mode, setMode] = useState<'single' | 'bulk'>('single');
    const [busy, setBusy] = useState(false);
    const [step, setStep] = useState(0);
    const [usage, setUsage] = useState(initialUsage);
    const [detected, setDetected] = useState<ScanDetected[] | null>(
        readStoredScan,
    );
    // Whether the shown results were restored from a previous session.
    const [fromStorage, setFromStorage] = useState<boolean>(
        () => readStoredScan() !== null,
    );
    // The photo the user just scanned (data URL), shown alongside each match.
    const [photo, setPhoto] = useState<string | null>(null);
    const fileRef = useRef<HTMLInputElement>(null);
    const libraryRef = useRef<HTMLInputElement>(null);

    const steps = STEP_COPY[mode];

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
        storeScan(null);
    };

    const onFile = async (file: File) => {
        setStep(0);
        setBusy(true);
        setDetected(null);
        setFromStorage(false);

        try {
            const image = await downscale(file);
            setPhoto(`data:image/jpeg;base64,${image}`);
            const res = await fetch('/scan', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ image, media_type: 'image/jpeg', mode }),
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
            setDetected(result.detected);
            storeScan(result.detected);

            if (result.detected.length === 0) {
                toast.message('No cards detected in that photo.');
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

    return (
        <>
            <Head title="Scan" />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="inline-flex rounded-md border border-border p-0.5 text-sm">
                        {(['single', 'bulk'] as const).map((m) => (
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
                                {m === 'single' ? 'Single card' : 'Bulk (page)'}
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

                <Card>
                    <CardContent className="pt-6">
                        <button
                            type="button"
                            onClick={() => fileRef.current?.click()}
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

                        {/* Camera forces the rear camera; the library button has
                            no capture, so it opens the photo picker / files. */}
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
                    </CardContent>
                </Card>

                {detected && detected.length > 0 && (
                    <div className="space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-muted-foreground">
                                {fromStorage
                                    ? 'Your recent scan'
                                    : `Detected ${detected.length} ${detected.length === 1 ? 'card' : 'cards'} — confirm and add`}
                            </h2>
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
                        {fromStorage && (
                            <p className="text-xs text-muted-foreground">
                                Picked up where you left off. Confirm and add, or
                                clear to start a new scan.
                            </p>
                        )}
                        {detected.map((d, i) => (
                            <ScanConfirmCard
                                key={i}
                                detected={d}
                                scanPhoto={photo}
                                gradingCompanies={gradingCompanies}
                            />
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
