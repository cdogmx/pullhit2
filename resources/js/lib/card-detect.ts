/**
 * Lightweight card-in-frame detection for the live scanner. No ML, no OpenCV —
 * just a gradient-energy bounding box that finds where a card sits in the frame
 * so the scanner can lock onto (and crop to) a card ANYWHERE in view, not only
 * one centered and filling a fixed box.
 *
 * The idea: a trading card is a rectangle of high visual detail (art, text, a
 * crisp border) sitting on a comparatively plain background (hand, table, mat).
 * Sum the per-pixel gradient down each column and across each row, then trim the
 * low-energy margins — what's left is the card's box. A score (detail inside the
 * box vs. outside) tells the caller how confidently a card stands out, so a busy
 * background doesn't trigger a false capture.
 *
 * `detectCardBox` is pure (operates on a grayscale array) so it can be reasoned
 * about and tested in isolation; the DOM sampling lives in the caller.
 */

export type NormBox = { x: number; y: number; width: number; height: number };

export type CardDetection = {
    /** Card bounding box, normalized 0–1 with origin top-left. */
    box: NormBox;
    /** Mean gradient inside the box ÷ outside it. Higher = stands out more. */
    score: number;
};

export type DetectOptions = {
    /** Fraction of total edge energy trimmed from each margin (per axis). */
    trim?: number;
    /** Padding added to each side of the box, as a fraction of that dimension. */
    pad?: number;
    /** Reject boxes covering less of the frame than this (area fraction). */
    minArea?: number;
    /** Reject boxes covering more of the frame than this (already fills it). */
    maxArea?: number;
    /** Reject boxes whose width/height ratio falls outside [min, max]. */
    aspect?: [number, number];
    /** Reject when inside/outside detail ratio is below this (not card-like). */
    minScore?: number;
    /**
     * When true (default), outside energy near the box is down-weighted so a
     * patterned playmat under the card doesn't kill the score as hard as a
     * competing detailed object elsewhere in frame.
     */
    centerWeight?: boolean;
};

const DEFAULTS: Required<DetectOptions> = {
    trim: 0.07,
    pad: 0.05,
    minArea: 0.08,
    maxArea: 0.98,
    // Portrait cards are ~5:7 (0.71). Allow more slack for tilt, hand, slab
    // labels, and mild landscape holds — still reject very wide scenes.
    aspect: [0.38, 1.35],
    // Slightly softer than 1.5 so real tables still lock; IoU hold gates false fires.
    minScore: 1.25,
    centerWeight: true,
};

/**
 * Intersection-over-union of two normalized boxes. 0 = no overlap, 1 = identical.
 * Used by the live scanner to require a *stable* lock before auto-capture.
 */
export function boxIoU(a: NormBox, b: NormBox): number {
    const ax1 = a.x + a.width;
    const ay1 = a.y + a.height;
    const bx1 = b.x + b.width;
    const by1 = b.y + b.height;

    const ix0 = Math.max(a.x, b.x);
    const iy0 = Math.max(a.y, b.y);
    const ix1 = Math.min(ax1, bx1);
    const iy1 = Math.min(ay1, by1);

    const iw = Math.max(0, ix1 - ix0);
    const ih = Math.max(0, iy1 - iy0);
    const inter = iw * ih;

    if (inter <= 0) {
        return 0;
    }

    const union = a.width * a.height + b.width * b.height - inter;

    return union > 0 ? inter / union : 0;
}

/** Average of normalized boxes (for a less jittery crop at capture time). */
export function averageBoxes(boxes: NormBox[]): NormBox | null {
    if (boxes.length === 0) {
        return null;
    }

    let x = 0;
    let y = 0;
    let w = 0;
    let h = 0;

    for (const b of boxes) {
        x += b.x;
        y += b.y;
        w += b.width;
        h += b.height;
    }

    const n = boxes.length;

    return { x: x / n, y: y / n, width: w / n, height: h / n };
}

/**
 * Find the tightest [lo, hi] index span containing all but `trim` of the energy
 * at each end — i.e. drop the sparse background margins. Returns null when the
 * axis carries no energy at all (a blank frame).
 */
function energySpan(energy: Float64Array, n: number, trim: number): [number, number] | null {
    let total = 0;

    for (let i = 0; i < n; i++) {
        total += energy[i];
    }

    if (total <= 0) {
        return null;
    }

    const cut = total * trim;

    let lo = 0;
    let acc = 0;

    while (lo < n - 1 && acc + energy[lo] < cut) {
        acc += energy[lo];
        lo++;
    }

    let hi = n - 1;
    acc = 0;

    while (hi > lo && acc + energy[hi] < cut) {
        acc += energy[hi];
        hi--;
    }

    return [lo, hi];
}

/**
 * Detect a card's bounding box in a grayscale frame, or null when nothing
 * card-like stands out. `gray` is row-major, length `w * h`, values 0–255.
 */
export function detectCardBox(
    gray: Uint8ClampedArray | number[],
    w: number,
    h: number,
    options: DetectOptions = {},
): CardDetection | null {
    const opt = { ...DEFAULTS, ...options };

    if (w < 8 || h < 8) {
        return null;
    }

    // Per-pixel gradient magnitude, accumulated into column and row profiles.
    const colE = new Float64Array(w);
    const rowE = new Float64Array(h);
    const grad = new Float64Array(w * h);

    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            const o = y * w + x;
            const g =
                Math.abs(gray[o + 1] - gray[o - 1]) +
                Math.abs(gray[o + w] - gray[o - w]);
            grad[o] = g;
            colE[x] += g;
            rowE[y] += g;
        }
    }

    const cs = energySpan(colE, w, opt.trim);
    const rs = energySpan(rowE, h, opt.trim);

    if (!cs || !rs) {
        return null;
    }

    // Pad the span outward (recovering the card's border, trimmed as a margin)
    // and clamp to the frame.
    const padX = Math.round(w * opt.pad);
    const padY = Math.round(h * opt.pad);
    const x0 = Math.max(0, cs[0] - padX);
    const x1 = Math.min(w - 1, cs[1] + padX);
    const y0 = Math.max(0, rs[0] - padY);
    const y1 = Math.min(h - 1, rs[1] + padY);

    const bw = x1 - x0 + 1;
    const bh = y1 - y0 + 1;
    const area = (bw * bh) / (w * h);

    if (area < opt.minArea || area > opt.maxArea) {
        return null;
    }

    const ratio = bw / bh;

    if (ratio < opt.aspect[0] || ratio > opt.aspect[1]) {
        return null;
    }

    // How much the boxed region stands out: mean gradient inside vs. outside.
    // Optional center-weight: far outside pixels count less so mats under the
    // card don't dominate, while a second object far away still pulls score down.
    const cx = (x0 + x1) / 2;
    const cy = (y0 + y1) / 2;
    // Distance scale: ~half the diagonal of the box.
    const distScale = Math.max(1, Math.hypot(bw, bh) * 0.55);

    let inSum = 0;
    let inN = 0;
    let outSum = 0;
    let outN = 0;

    for (let y = 1; y < h - 1; y++) {
        const inRow = y >= y0 && y <= y1;

        for (let x = 1; x < w - 1; x++) {
            const o = y * w + x;
            const g = grad[o];

            if (inRow && x >= x0 && x <= x1) {
                inSum += g;
                inN++;
            } else if (opt.centerWeight) {
                const d = Math.hypot(x - cx, y - cy) / distScale;
                // Near the box (d~1): weight ~0.55; far (d~3+): weight ~1.
                const weight = 0.45 + 0.55 * Math.min(1, Math.max(0, d - 0.6));
                outSum += g * weight;
                outN += weight;
            } else {
                outSum += g;
                outN++;
            }
        }
    }

    const inMean = inN > 0 ? inSum / inN : 0;
    const outMean = outN > 0 ? outSum / outN : 0;
    const score = inMean / (outMean + 1e-6);

    // A card is a distinct detailed region; if the whole frame is busy (score
    // near 1) it isn't standing out and we shouldn't lock on. When there's no
    // detail outside at all, treat it as a strong, clean detection.
    if (outN > 0 && score < opt.minScore) {
        return null;
    }

    return {
        box: { x: x0 / w, y: y0 / h, width: bw / w, height: bh / h },
        score: outN > 0 ? score : opt.minScore * 2,
    };
}

/**
 * Convert an ImageData-style RGBA buffer to a grayscale array (Rec. 601 luma).
 */
export function toGray(data: Uint8ClampedArray, w: number, h: number): Uint8ClampedArray {
    const gray = new Uint8ClampedArray(w * h);

    for (let i = 0, j = 0; i < data.length; i += 4, j++) {
        gray[j] = data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114;
    }

    return gray;
}
