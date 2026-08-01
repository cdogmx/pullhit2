import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { boxIoU, detectCardBox } from './card-detect.ts';
import type { NormBox } from './card-detect.ts';

// Frames are built at the same shape as the 280px proxy the live scanner samples.
const W = 210;
const H = 280;

function frame(fill = 40): Uint8ClampedArray {
    return new Uint8ClampedArray(W * H).fill(fill);
}

/** Fill a rect with deterministic pseudo-random detail — stands in for card art. */
function detail(
    g: Uint8ClampedArray,
    x0: number,
    y0: number,
    w: number,
    h: number,
    amp = 90,
    base = 150,
    seed = 1,
): void {
    let s = seed;

    for (let y = y0; y < y0 + h; y++) {
        for (let x = x0; x < x0 + w; x++) {
            if (x < 0 || y < 0 || x >= W || y >= H) {
                continue;
            }

            s = (s * 1103515245 + 12345) & 0x7fffffff;
            g[y * W + x] = base + ((s >> 16) % amp) - amp / 2;
        }
    }
}

/** A card: a detailed rect inside a bright border. */
function card(
    g: Uint8ClampedArray,
    x0: number,
    y0: number,
    w: number,
    h: number,
    seed = 1,
): void {
    detail(g, x0, y0, w, h, 110, 140, seed);

    for (let x = x0; x < x0 + w; x++) {
        for (const y of [y0, y0 + 1, y0 + h - 2, y0 + h - 1]) {
            if (y >= 0 && y < H && x >= 0 && x < W) {
                g[y * W + x] = 245;
            }
        }
    }

    for (let y = y0; y < y0 + h; y++) {
        for (const x of [x0, x0 + 1, x0 + w - 2, x0 + w - 1]) {
            if (y >= 0 && y < H && x >= 0 && x < W) {
                g[y * W + x] = 245;
            }
        }
    }
}

/** Normalized box of a pixel rect, for comparing against a detection. */
function expected(x: number, y: number, w: number, h: number): NormBox {
    return { x: x / W, y: y / H, width: w / W, height: h / H };
}

describe('detectCardBox', () => {
    it('locks onto a card on a plain background', () => {
        const g = frame();
        card(g, 55, 60, 100, 150);

        const found = detectCardBox(g, W, H);

        assert.ok(found, 'expected a detection');
        assert.ok(
            boxIoU(found.box, expected(55, 60, 100, 150)) > 0.6,
            `box should track the card, got ${JSON.stringify(found.box)}`,
        );
    });

    it('does not let background texture inflate the box', () => {
        const g = frame();
        detail(g, 0, 0, W, H, 18, 60, 7); // faint desk texture across the frame
        card(g, 55, 60, 100, 150);

        const found = detectCardBox(g, W, H);

        assert.ok(found, 'expected a detection');
        // The card covers ~26% of the frame. Summing gradient down whole columns
        // and rows used to stretch the box to 54% here, and to 83% on a patterned
        // mat, which then cropped the capture to most of the scene.
        const area = found.box.width * found.box.height;
        assert.ok(
            area < 0.5,
            `box covered ${(area * 100).toFixed(0)}% of the frame`,
        );
    });

    it('ignores a second object elsewhere in the frame', () => {
        const g = frame();
        card(g, 15, 60, 90, 135);
        detail(g, 150, 90, 50, 70, 70, 120, 5); // a mug on the other side

        const found = detectCardBox(g, W, H);

        assert.ok(found, 'expected a detection');
        // Projecting onto axes put both objects in one span; the box must now
        // stop before the mug rather than swallow it.
        assert.ok(
            found.box.x + found.box.width < 150 / W + 0.08,
            `box ran into the mug: ${JSON.stringify(found.box)}`,
        );
    });

    it('picks one card when two are side by side', () => {
        const g = frame();
        card(g, 10, 70, 85, 125, 2);
        card(g, 115, 70, 85, 125, 9);

        const found = detectCardBox(g, W, H);

        // The combined span used to fail the aspect check, so nothing locked at all.
        assert.ok(found, 'expected a detection');
        const a = boxIoU(found.box, expected(10, 70, 85, 125));
        const b = boxIoU(found.box, expected(115, 70, 85, 125));
        assert.ok(
            Math.max(a, b) > 0.5,
            `box matched neither card (IoU ${a.toFixed(2)} / ${b.toFixed(2)})`,
        );
    });

    it('still locks when the card is held close and fills the frame', () => {
        const g = frame();
        card(g, 8, 10, 194, 260);

        const found = detectCardBox(g, W, H);

        assert.ok(found, 'a card filling the frame must still lock');
        assert.ok(found.box.width * found.box.height > 0.6);
    });

    it('rejects a blank frame', () => {
        assert.equal(detectCardBox(frame(60), W, H), null);
    });

    it('rejects a frame that is busy edge to edge', () => {
        const g = frame();
        detail(g, 0, 0, W, H, 120, 120, 13);

        // No plain background anywhere means nothing stands out as "the card",
        // and any inside/outside score would be meaningless.
        assert.equal(detectCardBox(g, W, H), null);
    });

    it('rejects something far too small to be the card in view', () => {
        const g = frame();
        detail(g, 95, 130, 18, 24, 110, 150, 6);

        assert.equal(detectCardBox(g, W, H), null);
    });
});

describe('boxIoU', () => {
    it('is 1 for identical boxes and 0 when disjoint', () => {
        const a: NormBox = { x: 0.1, y: 0.1, width: 0.4, height: 0.4 };

        assert.equal(boxIoU(a, a), 1);
        assert.equal(boxIoU(a, { x: 0.6, y: 0.6, width: 0.3, height: 0.3 }), 0);
    });
});
