import { ArrowRight, ImageIcon, Loader2, ScanLine, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    averageBoxes,
    boxIoU,
    detectCardBox,
    toGray,
} from '@/lib/card-detect';
import type { NormBox } from '@/lib/card-detect';
import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CatalogItem, ScanDetected } from '@/types';

const MAX_PX = 1568;

// Auto-capture tuning. Each tick we sample the whole frame at moderate res and
// run a bounding-box detector. Capture only when the box is *stable* (IoU), the
// frame is steady (pixel motion), and the score stays above the floor.
const PROXY_LONG = 280; // long edge of the detection proxy canvas
const DETECT_MS = 100;
const STABLE_MOTION = 10; // mean per-pixel frame delta (0–255) for "steady"
const STABLE_IOU = 0.82; // box must overlap previous stable lock this much
const STABLE_TICKS = 5; // ~500ms of good ticks before auto-fire
const MIN_HOLD_SCORE = 1.2; // sustained score floor while holding
const COOLDOWN_MS = 1600;
// After a shot, re-arm when the box moves this much (or card leaves) so users
// can scan the next card without removing the previous one from view forever.
const REARM_IOU = 0.45;
const BOX_HISTORY = 5;

type DetectState =
    | 'off'
    | 'search'
    | 'locked' // card found but not yet steady
    | 'hold' // steady lock — about to fire
    | 'captured'
    | 'cooldown';

/**
 * Map a video-frame-normalized box to pixels over the (object-cover) video
 * element, undoing the center-crop cover scaling applies. Returns null until the
 * element has a rendered and intrinsic size.
 */
function coverRect(
    box: NormBox,
    v: HTMLVideoElement,
): { left: number; top: number; width: number; height: number } | null {
    if (!v.videoWidth || !v.clientWidth) {
        return null;
    }

    const cover = Math.max(
        v.clientWidth / v.videoWidth,
        v.clientHeight / v.videoHeight,
    );
    const dispW = v.videoWidth * cover;
    const dispH = v.videoHeight * cover;
    const cropX = (dispW - v.clientWidth) / 2;
    const cropY = (dispH - v.clientHeight) / 2;

    return {
        left: box.x * dispW - cropX,
        top: box.y * dispH - cropY,
        width: box.width * dispW,
        height: box.height * dispH,
    };
}

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
    const [auto, setAuto] = useState(true);
    const [detect, setDetect] = useState<DetectState>('search');
    // The lock-on box in element pixels (object-cover mapped), for the overlay.
    // Computed in the detection tick so render never reads a ref. Null = no lock.
    const [lockRect, setLockRect] = useState<{
        left: number;
        top: number;
        width: number;
        height: number;
    } | null>(null);

    // Refs so the detection interval always sees the latest values without being
    // torn down and rebuilt each render. Synced in an effect (updating a ref
    // during render isn't allowed).
    const busyRef = useRef(busy);
    const autoRef = useRef(auto);
    useEffect(() => {
        busyRef.current = busy;
        autoRef.current = auto;
    });
    // Latest stable-ish box (normalized), read by capture() to crop to the card.
    const boxRef = useRef<NormBox | null>(null);

    useEffect(() => {
        let cancelled = false;

        navigator.mediaDevices
            ?.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                    // Prefer a usable still-ish stream; browsers may ignore.
                    width: { ideal: 1920 },
                    height: { ideal: 1080 },
                },
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

    // Grab the current video frame and hand up a JPEG. When a card is locked we
    // crop to its box (from the FULL-resolution frame) so the card fills the sent
    // image — that's what makes an off-center or smallish card readable. With no
    // lock (manual shutter, nothing detected) we send the whole frame.
    const capture = (cropBox: NormBox | null = boxRef.current) => {
        const video = videoRef.current;

        if (!video || busy || !video.videoWidth) {
            return;
        }

        const vw = video.videoWidth;
        const vh = video.videoHeight;

        // Source rect: the locked card (in pixels) or the whole frame.
        // Slight extra pad so we don't clip borders that the detector trimmed.
        let sx = 0;
        let sy = 0;
        let sw = vw;
        let sh = vh;

        if (cropBox) {
            const pad = 0.03;
            sx = Math.max(0, (cropBox.x - pad) * vw);
            sy = Math.max(0, (cropBox.y - pad) * vh);
            sw = Math.min(vw - sx, (cropBox.width + pad * 2) * vw);
            sh = Math.min(vh - sy, (cropBox.height + pad * 2) * vh);
        }

        const scale = Math.min(1, MAX_PX / Math.max(sw, sh));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(sw * scale));
        canvas.height = Math.max(1, Math.round(sh * scale));
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        ctx.drawImage(video, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
        onShutter(canvas.toDataURL('image/jpeg', 0.85).split(',')[1]);
    };

    // Keep the interval's view of capture() current without re-creating the
    // interval (updating a ref during render isn't allowed, so do it in an effect).
    const captureRef = useRef(capture);
    useEffect(() => {
        captureRef.current = capture;
    });

    // Auto-capture: locate the card, require IoU-stable box + low motion +
    // sustained score, then fire with an averaged box. Re-arm after cooldown when
    // the card leaves OR the lock moves enough to look like a new card.
    useEffect(() => {
        if (!ready || error) {
            return;
        }

        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        let prev: Uint8ClampedArray | null = null;
        let stable = 0;
        let phase: 'search' | 'cooldown' = 'search';
        let cooldownUntil = 0;
        let lastFiredBox: NormBox | null = null;
        const recentBoxes: NormBox[] = [];
        let lockedBox: NormBox | null = null; // EMA-smoothed lock for overlay + IoU

        const id = window.setInterval(() => {
            const v = videoRef.current;

            if (!v || !v.videoWidth || !ctx) {
                return;
            }

            // Size the proxy from the LIVE frame each tick (robust to a late-
            // arriving or rotated stream); it preserves the frame's aspect so the
            // detected box maps straight back over the video.
            const scale = PROXY_LONG / Math.max(v.videoWidth, v.videoHeight);
            const pw = Math.max(8, Math.round(v.videoWidth * scale));
            const ph = Math.max(8, Math.round(v.videoHeight * scale));

            if (canvas.width !== pw || canvas.height !== ph) {
                canvas.width = pw;
                canvas.height = ph;
                prev = null; // dimensions changed — the motion baseline is stale
            }

            ctx.drawImage(v, 0, 0, pw, ph);
            const { data } = ctx.getImageData(0, 0, pw, ph);
            const gray = toGray(data, pw, ph);

            // Motion = mean per-pixel change vs the previous tick (steadiness).
            let motion = Infinity;

            if (prev) {
                let sum = 0;

                for (let k = 0; k < gray.length; k++) {
                    sum += Math.abs(gray[k] - prev[k]);
                }

                motion = sum / gray.length;
            }

            prev = gray;

            if (!autoRef.current) {
                setDetect('off');
                setLockRect(null);
                boxRef.current = null;
                stable = 0;
                recentBoxes.length = 0;
                lockedBox = null;

                return;
            }

            // Locate the card. Keep the overlay live even while busy/cooling down.
            const found = detectCardBox(gray, pw, ph);

            if (found) {
                // Smooth the lock so the overlay doesn't thrash every tick.
                if (lockedBox) {
                    const a = 0.35;
                    lockedBox = {
                        x: lockedBox.x * (1 - a) + found.box.x * a,
                        y: lockedBox.y * (1 - a) + found.box.y * a,
                        width:
                            lockedBox.width * (1 - a) + found.box.width * a,
                        height:
                            lockedBox.height * (1 - a) + found.box.height * a,
                    };
                } else {
                    lockedBox = { ...found.box };
                }

                boxRef.current = lockedBox;
                setLockRect(coverRect(lockedBox, v));
            } else {
                lockedBox = null;
                boxRef.current = null;
                setLockRect(null);
            }

            if (busyRef.current) {
                return;
            }

            // Cooldown / re-arm: either the card left, or the lock moved enough
            // that it looks like a different card in frame.
            if (phase === 'cooldown') {
                const now = Date.now();

                if (now < cooldownUntil) {
                    setDetect('cooldown');

                    return;
                }

                const left = !found;
                const moved =
                    found &&
                    lastFiredBox != null &&
                    boxIoU(found.box, lastFiredBox) < REARM_IOU;

                if (left || moved) {
                    phase = 'search';
                    stable = 0;
                    recentBoxes.length = 0;
                    lastFiredBox = null;
                    setDetect(found ? 'locked' : 'search');
                } else {
                    setDetect('cooldown');
                }

                return;
            }

            // Searching / locking.
            if (!found || !lockedBox) {
                stable = 0;
                recentBoxes.length = 0;
                setDetect('search');

                return;
            }

            // Box stability: compare this detection to the last accepted hold box
            // (or the smoothed lock if we have history).
            const prevBox =
                recentBoxes.length > 0
                    ? recentBoxes[recentBoxes.length - 1]
                    : lockedBox;
            const iou = boxIoU(found.box, prevBox);
            const steady =
                motion <= STABLE_MOTION &&
                iou >= STABLE_IOU &&
                found.score >= MIN_HOLD_SCORE;

            if (steady) {
                recentBoxes.push({ ...found.box });

                if (recentBoxes.length > BOX_HISTORY) {
                    recentBoxes.shift();
                }

                stable += 1;
                setDetect(stable >= STABLE_TICKS - 1 ? 'hold' : 'locked');

                if (stable >= STABLE_TICKS) {
                    const crop =
                        averageBoxes(recentBoxes) ?? found.box;
                    boxRef.current = crop;
                    lastFiredBox = { ...crop };
                    stable = 0;
                    recentBoxes.length = 0;
                    phase = 'cooldown';
                    cooldownUntil = Date.now() + COOLDOWN_MS;
                    setDetect('captured');
                    captureRef.current(crop);
                }
            } else {
                // Keep a weak lock for the overlay, but reset the hold counter.
                stable = 0;
                recentBoxes.length = 0;
                setDetect(
                    found.score >= MIN_HOLD_SCORE ? 'locked' : 'search',
                );
            }
        }, DETECT_MS);

        return () => window.clearInterval(id);
    }, [ready, error]);

    const lockColor =
        detect === 'captured'
            ? 'border-green-400'
            : detect === 'hold'
              ? 'border-amber-400'
              : detect === 'locked'
                ? 'border-emerald-400'
                : detect === 'cooldown'
                  ? 'border-white/40'
                  : 'border-emerald-400/60';

    const statusCopy = (() => {
        switch (detect) {
            case 'captured':
                return 'Captured!';
            case 'hold':
                return 'Steady — capturing…';
            case 'locked':
                return 'Card found — hold steady…';
            case 'cooldown':
                return 'Move to the next card…';
            case 'search':
                return 'Point at a card — it captures automatically';
            default:
                return null;
        }
    })();

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

                {/* Detection overlay. The lock-on box follows the card wherever
                    it is in the frame; a faint centered guide shows when nothing
                    is detected yet so the viewfinder never looks empty. */}
                <div className="pointer-events-none absolute inset-0">
                    {lockRect ? (
                        <div
                            className={cn(
                                'absolute rounded-lg border-4 transition-colors',
                                lockColor,
                            )}
                            style={{
                                left: lockRect.left,
                                top: lockRect.top,
                                width: lockRect.width,
                                height: lockRect.height,
                            }}
                        />
                    ) : (
                        <div className="absolute inset-0 flex items-center justify-center">
                            <div className="aspect-[5/7] h-[62%] rounded-lg border-2 border-dashed border-white/25" />
                        </div>
                    )}

                    {auto && !busy && statusCopy && (
                        <div className="absolute inset-x-0 bottom-3 flex justify-center">
                            <span className="rounded-full bg-black/55 px-3 py-1 text-xs font-medium backdrop-blur">
                                {statusCopy}
                            </span>
                        </div>
                    )}
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

                {/* Top-right: Auto toggle, or the "reading" indicator when busy. */}
                {busy ? (
                    <div className="absolute top-3 right-3 flex items-center gap-1.5 rounded-full bg-black/60 px-3 py-1 text-xs font-medium backdrop-blur">
                        <Loader2 className="size-3.5 animate-spin" />
                        Reading…
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={() => setAuto((a) => !a)}
                        aria-pressed={auto}
                        className={cn(
                            'absolute top-3 right-3 flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold backdrop-blur transition-colors',
                            auto
                                ? 'bg-emerald-500 text-white'
                                : 'bg-black/55 text-white/80',
                        )}
                    >
                        <ScanLine className="size-3.5" />
                        Auto {auto ? 'on' : 'off'}
                    </button>
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
                    onClick={() => capture()}
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
