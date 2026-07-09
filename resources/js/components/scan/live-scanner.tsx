import { ArrowRight, ImageIcon, Loader2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CatalogItem, ScanDetected } from '@/types';

const MAX_PX = 1568;

/**
 * Continuous live-camera scanner (Collectr-style): a framed viewfinder, a shutter
 * that grabs the current frame and hands it up to be read, a running strip of
 * everything captured so far with a live total, and a "Next" to review + add. The
 * actual read/match is the parent's job (onShutter) — this owns only the camera.
 */
export function LiveScanner({
    scanned,
    chosenCards,
    total,
    busy,
    onShutter,
    onGallery,
    onNext,
    onExit,
}: {
    scanned: ScanDetected[];
    /** The chosen catalog match per scanned card (index-aligned). */
    chosenCards: (CatalogItem | null)[];
    total: number;
    busy: boolean;
    onShutter: (imageBase64: string) => void;
    onGallery: () => void;
    onNext: () => void;
    onExit: () => void;
}) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [ready, setReady] = useState(false);

    useEffect(() => {
        let cancelled = false;

        navigator.mediaDevices
            ?.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            })
            .then((stream) => {
                if (cancelled) {
                    stream.getTracks().forEach((t) => t.stop());

                    return;
                }

                streamRef.current = stream;

                if (videoRef.current) {
                    videoRef.current.srcObject = stream;
                    videoRef.current.play().catch(() => {});
                }
            })
            .catch(() =>
                !cancelled &&
                setError(
                    'Camera unavailable — grant camera access, or upload from your library.',
                ),
            );

        return () => {
            cancelled = true;
            streamRef.current?.getTracks().forEach((t) => t.stop());
        };
    }, []);

    // Grab the current video frame, downscale to a JPEG, and hand it up.
    const capture = () => {
        const video = videoRef.current;

        if (!video || busy || !video.videoWidth) {
            return;
        }

        const scale = Math.min(
            1,
            MAX_PX / Math.max(video.videoWidth, video.videoHeight),
        );
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        onShutter(canvas.toDataURL('image/jpeg', 0.85).split(',')[1]);
    };

    return (
        <div className="relative overflow-hidden rounded-2xl border border-border bg-black text-white">
            {/* Viewfinder */}
            <div className="relative aspect-[3/4] w-full sm:aspect-[4/3]">
                <video
                    ref={videoRef}
                    playsInline
                    muted
                    onLoadedMetadata={() => setReady(true)}
                    className="size-full object-cover"
                />

                {/* Card frame overlay */}
                <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div className="relative aspect-[5/7] h-[70%]">
                        {(['tl', 'tr', 'bl', 'br'] as const).map((corner) => (
                            <span
                                key={corner}
                                className={cn(
                                    'absolute size-8 border-emerald-400',
                                    corner === 'tl' &&
                                        'top-0 left-0 rounded-tl-lg border-t-4 border-l-4',
                                    corner === 'tr' &&
                                        'top-0 right-0 rounded-tr-lg border-t-4 border-r-4',
                                    corner === 'bl' &&
                                        'bottom-0 left-0 rounded-bl-lg border-b-4 border-l-4',
                                    corner === 'br' &&
                                        'right-0 bottom-0 rounded-br-lg border-r-4 border-b-4',
                                )}
                            />
                        ))}
                    </div>
                </div>

                {/* Close */}
                <button
                    type="button"
                    onClick={onExit}
                    aria-label="Exit scanner"
                    className="absolute top-3 left-3 flex size-9 items-center justify-center rounded-full bg-black/50 backdrop-blur transition-colors hover:bg-black/70"
                >
                    <X className="size-5" />
                </button>

                {/* Busy / camera-error states */}
                {busy && (
                    <div className="absolute top-3 right-3 flex items-center gap-1.5 rounded-full bg-black/60 px-3 py-1 text-xs font-medium backdrop-blur">
                        <Loader2 className="size-3.5 animate-spin" />
                        Reading…
                    </div>
                )}
                {error && (
                    <div className="absolute inset-0 flex items-center justify-center bg-black/80 p-6 text-center text-sm">
                        {error}
                    </div>
                )}
                {!ready && !error && (
                    <div className="absolute inset-0 flex items-center justify-center bg-black/60">
                        <Loader2 className="size-6 animate-spin" />
                    </div>
                )}
            </div>

            {/* Captured strip + running total */}
            {scanned.length > 0 && (
                <div className="border-t border-white/10 bg-neutral-950/90 px-3 py-2">
                    <div className="mb-1 flex items-center justify-end">
                        <span className="text-sm font-bold text-emerald-400 tabular-nums">
                            Total: {formatMoney(total)}
                        </span>
                    </div>
                    <div className="flex gap-2 overflow-x-auto pb-1">
                        {scanned.map((d, i) => {
                            const card = chosenCards[i] ?? null;
                            const img = card?.image_url ?? d.thumbnail ?? null;
                            const median = card?.market_value?.median;

                            return (
                                <div
                                    key={i}
                                    className="flex w-28 shrink-0 items-center gap-2 rounded-md border border-white/10 bg-white/5 p-1.5"
                                    title={
                                        card?.display_name ??
                                        card?.name ??
                                        d.identified.name ??
                                        'Unknown'
                                    }
                                >
                                    {img ? (
                                        <img
                                            src={img}
                                            alt=""
                                            className="h-12 w-9 shrink-0 rounded object-cover"
                                        />
                                    ) : (
                                        <div className="flex h-12 w-9 shrink-0 items-center justify-center rounded bg-white/10 text-[10px] text-white/50">
                                            ?
                                        </div>
                                    )}
                                    <div className="min-w-0">
                                        <p className="truncate text-[11px] font-medium">
                                            {card?.name ??
                                                d.identified.name ??
                                                'Unknown'}
                                        </p>
                                        <p className="text-[11px] text-emerald-400 tabular-nums">
                                            {median != null
                                                ? formatMoney(median)
                                                : '—'}
                                        </p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}

            {/* Controls: gallery · shutter · next */}
            <div className="flex items-center justify-between gap-4 bg-neutral-950 px-6 py-4">
                <button
                    type="button"
                    onClick={onGallery}
                    aria-label="Upload from library"
                    className="flex size-11 items-center justify-center rounded-full bg-white/10 transition-colors hover:bg-white/20"
                >
                    <ImageIcon className="size-5" />
                </button>

                <button
                    type="button"
                    onClick={capture}
                    disabled={busy || !ready}
                    aria-label="Capture card"
                    className="flex size-16 items-center justify-center rounded-full bg-white ring-4 ring-white/30 transition-transform active:scale-95 disabled:opacity-50"
                >
                    {busy ? (
                        <Loader2 className="size-7 animate-spin text-neutral-900" />
                    ) : (
                        <span className="size-12 rounded-full border-2 border-neutral-300" />
                    )}
                </button>

                <button
                    type="button"
                    onClick={onNext}
                    disabled={scanned.length === 0}
                    className="flex h-11 items-center gap-1.5 rounded-full bg-emerald-500 px-4 text-sm font-semibold text-white transition-colors hover:bg-emerald-600 disabled:opacity-40"
                >
                    Next
                    <ArrowRight className="size-4" />
                </button>
            </div>
        </div>
    );
}
