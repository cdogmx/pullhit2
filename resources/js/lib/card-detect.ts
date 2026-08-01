/**
 * Lightweight card-in-frame detection for the live scanner. No ML, no OpenCV —
 * a coarse detail map plus connected-component analysis that finds where a card
 * sits in the frame so the scanner can lock onto (and crop to) a card ANYWHERE
 * in view, not only one centered and filling a fixed box.
 *
 * The idea: a trading card is a rectangle of high visual detail (art, text, a
 * crisp border) sitting on a comparatively plain background (hand, table, mat).
 * Score every cell of a coarse grid by how much gradient it carries, keep the
 * cells above an adaptive threshold, then take the best *connected blob* of
 * them. A score (detail inside the box vs. outside) tells the caller how
 * confidently a card stands out, so a busy background doesn't trigger a false
 * capture.
 *
 * This used to sum gradient down whole columns and rows and trim the sparse
 * margins. That cannot localize: a mug on the far side of the frame extends the
 * column span just as much as the card does, so the box grew to cover both, and
 * on a patterned mat it grew to the whole frame. The failure was self-masking —
 * an inflated box has nearly the same detail inside as outside, so the score
 * collapsed toward 1.0 and the scanner simply never locked. Connected components
 * keep separate objects separate.
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
    /** Cells along the frame's long edge for the detail map. */
    cells?: number;
    /**
     * A cell counts as "detail" when its gradient exceeds the frame mean times
     * this. Above 1 so a uniformly textured mat doesn't light up every cell.
     */
    detailFactor?: number;
};

const DEFAULTS: Required<DetectOptions> = {
    pad: 0.035,
    minArea: 0.05,
    maxArea: 0.98,
    // Portrait cards are ~5:7 (0.71). Allow slack for tilt, hand, slab labels,
    // and mild landscape holds — still reject very wide scenes.
    aspect: [0.38, 1.35],
    minScore: 1.25,
    cells: 32,
    detailFactor: 1.15,
};

/** A card's width/height, and the same card held landscape. */
const CARD_ASPECT = 5 / 7;
const CARD_ASPECT_LANDSCAPE = 7 / 5;

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

/** How card-like a width/height ratio is: 1 at 5:7 (or 7:5), falling off. */
function aspectFit(ratio: number): number {
    const err = Math.min(
        Math.abs(ratio - CARD_ASPECT) / CARD_ASPECT,
        Math.abs(ratio - CARD_ASPECT_LANDSCAPE) / CARD_ASPECT_LANDSCAPE,
    );

    return 1 / (1 + err * err * 4);
}

/**
 * Otsu's threshold over the cell detail values: the cut that best separates them
 * into two groups (background vs. card). Works at any coverage, which a
 * threshold defined relative to the frame mean does not — a card filling most of
 * the frame drags the mean up to its own level.
 */
function otsu(values: Float64Array): number {
    let max = 0;

    for (let i = 0; i < values.length; i++) {
        if (values[i] > max) {
            max = values[i];
        }
    }

    if (max <= 0) {
        return 0;
    }

    const BINS = 64;
    const hist = new Float64Array(BINS);

    for (let i = 0; i < values.length; i++) {
        hist[Math.min(BINS - 1, ((values[i] / max) * BINS) | 0)]++;
    }

    const total = values.length;
    let sumAll = 0;

    for (let b = 0; b < BINS; b++) {
        sumAll += b * hist[b];
    }

    let bgWeight = 0;
    let bgSum = 0;
    let best = 0;
    let bestVar = -1;

    for (let b = 0; b < BINS; b++) {
        bgWeight += hist[b];

        if (bgWeight === 0) {
            continue;
        }

        const fgWeight = total - bgWeight;

        if (fgWeight === 0) {
            break;
        }

        bgSum += b * hist[b];

        const bgMean = bgSum / bgWeight;
        const fgMean = (sumAll - bgSum) / fgWeight;
        const between = bgWeight * fgWeight * (bgMean - fgMean) ** 2;

        if (between > bestVar) {
            bestVar = between;
            best = b;
        }
    }

    return ((best + 1) / BINS) * max;
}

type Blob = { x0: number; y0: number; x1: number; y1: number; cells: number };

/**
 * Label 4-connected runs of flagged cells and return each one's cell-space
 * bounding box. Iterative (an explicit stack) so a frame-filling blob can't
 * blow the call stack on a low-end phone.
 */
function blobs(flag: Uint8Array, cw: number, ch: number): Blob[] {
    const seen = new Uint8Array(cw * ch);
    const stack: number[] = [];
    const out: Blob[] = [];

    for (let start = 0; start < flag.length; start++) {
        if (!flag[start] || seen[start]) {
            continue;
        }

        seen[start] = 1;
        stack.push(start);

        let x0 = cw;
        let y0 = ch;
        let x1 = -1;
        let y1 = -1;
        let count = 0;

        while (stack.length > 0) {
            const at = stack.pop() as number;
            const x = at % cw;
            const y = (at - x) / cw;

            count++;

            if (x < x0) {
                x0 = x;
            }

            if (y < y0) {
                y0 = y;
            }

            if (x > x1) {
                x1 = x;
            }

            if (y > y1) {
                y1 = y;
            }

            if (x > 0 && flag[at - 1] && !seen[at - 1]) {
                seen[at - 1] = 1;
                stack.push(at - 1);
            }

            if (x < cw - 1 && flag[at + 1] && !seen[at + 1]) {
                seen[at + 1] = 1;
                stack.push(at + 1);
            }

            if (y > 0 && flag[at - cw] && !seen[at - cw]) {
                seen[at - cw] = 1;
                stack.push(at - cw);
            }

            if (y < ch - 1 && flag[at + cw] && !seen[at + cw]) {
                seen[at + cw] = 1;
                stack.push(at + cw);
            }
        }

        out.push({ x0, y0, x1, y1, cells: count });
    }

    return out;
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

    // 1) Per-pixel gradient magnitude.
    const grad = new Float64Array(w * h);
    let gradTotal = 0;

    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            const o = y * w + x;
            const g =
                Math.abs(gray[o + 1] - gray[o - 1]) +
                Math.abs(gray[o + w] - gray[o - w]);
            grad[o] = g;
            gradTotal += g;
        }
    }

    if (gradTotal <= 0) {
        return null;
    }

    // 2) Coarse detail map. One value per cell keeps the blob pass cheap and
    //    smooths over the gaps between a card's text and art.
    const cell = Math.max(4, Math.round(Math.max(w, h) / opt.cells));
    const cw = Math.max(1, Math.ceil(w / cell));
    const ch = Math.max(1, Math.ceil(h / cell));
    const cellSum = new Float64Array(cw * ch);
    const cellN = new Float64Array(cw * ch);

    for (let y = 1; y < h - 1; y++) {
        const cy = Math.min(ch - 1, (y / cell) | 0);

        for (let x = 1; x < w - 1; x++) {
            const ci = cy * cw + Math.min(cw - 1, (x / cell) | 0);
            cellSum[ci] += grad[y * w + x];
            cellN[ci]++;
        }
    }

    let mean = 0;
    let counted = 0;

    for (let i = 0; i < cellSum.length; i++) {
        if (cellN[i] > 0) {
            cellSum[i] /= cellN[i];
            mean += cellSum[i];
            counted++;
        }
    }

    if (counted === 0) {
        return null;
    }

    mean /= counted;

    // 3) Split the cells into background and detail. Otsu rather than "above the
    //    frame mean": once a card fills most of the frame the mean converges on
    //    the card's own detail level and a relative threshold flags almost
    //    nothing, so a card held close stopped being detected at all. Otsu finds
    //    the natural gap between the two populations at any coverage.
    const threshold = Math.max(
        otsu(cellSum),
        // Floor for a frame with no real background: keeps a uniformly textured
        // scene from splitting itself into "slightly busier" and "slightly less".
        mean * 0.25,
    );
    const flag = new Uint8Array(cw * ch);
    let detailCells = 0;

    for (let i = 0; i < flag.length; i++) {
        flag[i] = cellSum[i] > threshold ? 1 : 0;
        detailCells += flag[i];
    }

    if (detailCells === 0) {
        return null;
    }

    // 4) Every connected blob is a candidate. A card and a mug are now separate
    //    candidates rather than one box swallowing both.
    const candidates = blobs(flag, cw, ch);

    if (candidates.length === 0) {
        return null;
    }

    let best: CardDetection | null = null;
    let bestRank = 0;

    for (const blob of candidates) {
        // Cell space → pixels, padded to recover the border the detail map trims.
        const padX = Math.round(w * opt.pad);
        const padY = Math.round(h * opt.pad);
        const tight = {
            x0: blob.x0 * cell,
            y0: blob.y0 * cell,
            x1: Math.min(w - 1, (blob.x1 + 1) * cell - 1),
            y1: Math.min(h - 1, (blob.y1 + 1) * cell - 1),
        };

        const areaOf = (b: typeof tight) =>
            ((b.x1 - b.x0 + 1) * (b.y1 - b.y0 + 1)) / (w * h);

        if (areaOf(tight) > opt.maxArea) {
            // Detail edge to edge with no plain margin anywhere — a cluttered
            // scene, not a card. There is no background left to contrast against,
            // so any score here would be meaningless.
            continue;
        }

        const padded = {
            x0: Math.max(0, tight.x0 - padX),
            y0: Math.max(0, tight.y0 - padY),
            x1: Math.min(w - 1, tight.x1 + padX),
            y1: Math.min(h - 1, tight.y1 + padY),
        };

        // Keep the padding only while it leaves a margin to measure against.
        // A card held close already reaches the frame edges; padding it further
        // would swallow the last of the background and make the box unscoreable.
        const { x0, y0, x1, y1 } =
            areaOf(padded) > opt.maxArea ? tight : padded;

        const bw = x1 - x0 + 1;
        const bh = y1 - y0 + 1;
        const area = (bw * bh) / (w * h);

        if (area < opt.minArea) {
            continue;
        }

        const ratio = bw / bh;

        if (ratio < opt.aspect[0] || ratio > opt.aspect[1]) {
            continue;
        }

        // How much this region stands out: mean gradient inside vs. outside.
        let inSum = 0;
        let inN = 0;
        let outSum = 0;
        let outN = 0;

        for (let y = 1; y < h - 1; y++) {
            const inRow = y >= y0 && y <= y1;

            for (let x = 1; x < w - 1; x++) {
                const g = grad[y * w + x];

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
        // Clamp rather than divide by ~0: a card on a blank table used to score
        // in the tens of millions, which made the number meaningless to compare
        // candidates by (and impossible to reason about in a threshold).
        const score = outN > 0 ? inMean / Math.max(outMean, inMean * 0.02) : 50;

        if (score < opt.minScore) {
            continue;
        }

        // Prefer the candidate that is most card-shaped and most distinct;
        // size breaks ties so a card wins over a sticker beside it.
        const rank = score * aspectFit(ratio) * (0.35 + area);

        if (rank > bestRank) {
            bestRank = rank;
            best = {
                box: { x: x0 / w, y: y0 / h, width: bw / w, height: bh / h },
                score: Math.min(score, 50),
            };
        }
    }

    return best;
}

/**
 * Convert an ImageData-style RGBA buffer to a grayscale array (Rec. 601 luma).
 */
export function toGray(
    data: Uint8ClampedArray,
    w: number,
    h: number,
): Uint8ClampedArray {
    const gray = new Uint8ClampedArray(w * h);

    for (let i = 0, j = 0; i < data.length; i += 4, j++) {
        gray[j] = data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114;
    }

    return gray;
}
