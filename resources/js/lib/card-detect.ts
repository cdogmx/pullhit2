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
};

const DEFAULTS: Required<DetectOptions> = {
    trim: 0.06,
    pad: 0.04,
    minArea: 0.1,
    maxArea: 0.97,
    // Portrait cards are ~5:7 (0.71); allow slack for perspective, a bit of hand,
    // or a graded slab, but stay portrait-ish so a wide detailed background loses.
    aspect: [0.4, 1.15],
    minScore: 1.5,
};

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

    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            const o = y * w + x;
            const g =
                Math.abs(gray[o + 1] - gray[o - 1]) +
                Math.abs(gray[o + w] - gray[o - w]);
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
    let inSum = 0;
    let inN = 0;
    let outSum = 0;
    let outN = 0;

    for (let y = 1; y < h - 1; y++) {
        const inRow = y >= y0 && y <= y1;

        for (let x = 1; x < w - 1; x++) {
            const o = y * w + x;
            const g =
                Math.abs(gray[o + 1] - gray[o - 1]) +
                Math.abs(gray[o + w] - gray[o - w]);

            if (inRow && x >= x0 && x <= x1) {
                inSum += g;
                inN++;
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
