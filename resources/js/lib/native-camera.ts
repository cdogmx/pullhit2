import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';
import { Capacitor } from '@capacitor/core';

/** True when running inside the native iOS/Android shell (Capacitor). */
export const isNativeApp = (): boolean => Capacitor.isNativePlatform();

/**
 * Capture a photo with the device camera and return it as a base64 JPEG
 * (long edge ≤1568px, matching the web downscale), or null if the user cancels.
 * Native only — callers gate on isNativeApp(); on the web the file <input>
 * (with `capture`) is used instead.
 */
export async function captureNativePhoto(): Promise<string | null> {
    try {
        const photo = await Camera.getPhoto({
            quality: 85,
            width: 1568,
            resultType: CameraResultType.Base64,
            source: CameraSource.Camera,
            correctOrientation: true,
        });

        return photo.base64String ?? null;
    } catch {
        // User cancelled the camera, or permission denied.
        return null;
    }
}
