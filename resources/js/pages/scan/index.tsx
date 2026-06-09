import { Head } from '@inertiajs/react';
import { Camera, Loader2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { ScanConfirmCard } from '@/components/scan/scan-confirm-card';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { GradingCompanyOption, ScanDetected, ScanResult, ScanUsage } from '@/types';

type Props = { usage: ScanUsage; gradingCompanies: GradingCompanyOption[] };

const MAX_PX = 1568;

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

export default function ScanIndex({ usage: initialUsage, gradingCompanies }: Props) {
    const [mode, setMode] = useState<'single' | 'bulk'>('single');
    const [busy, setBusy] = useState(false);
    const [usage, setUsage] = useState(initialUsage);
    const [detected, setDetected] = useState<ScanDetected[] | null>(null);
    const fileRef = useRef<HTMLInputElement>(null);

    const onFile = async (file: File) => {
        setBusy(true);
        setDetected(null);

        try {
            const image = await downscale(file);
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

            if (result.detected.length === 0) {
                toast.message('No cards detected in that photo.');
            }
        } catch {
            toast.error('Could not process that image.');
        } finally {
            setBusy(false);

            if (fileRef.current) {
                fileRef.current.value = '';
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
                    </p>
                </div>

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
                                    ? 'Reading the card…'
                                    : mode === 'single'
                                      ? 'Take or upload a photo of one card'
                                      : 'Take or upload a photo of a binder page'}
                            </span>
                        </button>
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
                    </CardContent>
                </Card>

                {detected && detected.length > 0 && (
                    <div className="space-y-3">
                        <h2 className="text-sm font-semibold text-muted-foreground">
                            Detected {detected.length}{' '}
                            {detected.length === 1 ? 'card' : 'cards'} — confirm and add
                        </h2>
                        {detected.map((d, i) => (
                            <ScanConfirmCard
                                key={i}
                                detected={d}
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
