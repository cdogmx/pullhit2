import type { CropRect } from '@/components/grading/crop-box';

/**
 * Apply a fractional crop to a file, as a new file.
 *
 * Done in the browser so the pipeline receives exactly what was framed on
 * screen, and so a twelve-megapixel photo is not uploaded to have most of it
 * discarded server-side.
 *
 * PNG, not JPEG: the surface read is hunting faint scratches, and JPEG's
 * ringing around edges is exactly the artefact it would mistake for one.
 */
export async function cropFile(file: File, crop: CropRect): Promise<File> {
    const bitmap = await createImageBitmap(file);

    const sx = Math.round(crop.x * bitmap.width);
    const sy = Math.round(crop.y * bitmap.height);
    const sw = Math.max(1, Math.round(crop.w * bitmap.width));
    const sh = Math.max(1, Math.round(crop.h * bitmap.height));

    const canvas = document.createElement('canvas');
    canvas.width = sw;
    canvas.height = sh;

    const ctx = canvas.getContext('2d')!;
    ctx.drawImage(bitmap, sx, sy, sw, sh, 0, 0, sw, sh);
    bitmap.close();

    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/png'),
    );

    if (!blob) {
        return file;
    }

    return new File([blob], file.name.replace(/\.\w+$/, '') + '-crop.png', {
        type: 'image/png',
    });
}
